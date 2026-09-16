<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Webhook;

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Order\OrderCreator;
use Briqpay\Payment\Order\StateApplier;
use Briqpay\Payment\Order\StatusMapper;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Repository\SessionRepository;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Processes Briqpay's asynchronous notifications.
 *
 * The request body is treated as untrusted input: the handler takes only the
 * session id from it and then re-reads the session over the authenticated
 * Briqpay API, so the state it acts on always comes from Briqpay itself rather
 * than from whoever sent the POST.
 */
class WebhookHandler
{
    public const EVENT_ORDER_STATUS = 'order_status';
    public const EVENT_CAPTURE_STATUS = 'capture_status';
    public const EVENT_REFUND_STATUS = 'refund_status';

    /** @var SessionManager */
    private $sessionManager;

    /** @var OrderCreator */
    private $orderCreator;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var SessionRepository */
    private $sessionRepository;

    /** @var StateApplier */
    private $stateApplier;

    public function __construct(
        SessionManager $sessionManager,
        OrderCreator $orderCreator,
        ?OrderRepository $orderRepository = null,
        ?SessionRepository $sessionRepository = null,
        ?StateApplier $stateApplier = null
    ) {
        $this->sessionManager = $sessionManager;
        $this->orderCreator = $orderCreator;
        $this->orderRepository = $orderRepository !== null ? $orderRepository : new OrderRepository();
        $this->sessionRepository = $sessionRepository !== null ? $sessionRepository : new SessionRepository();
        $this->stateApplier = $stateApplier !== null ? $stateApplier : new StateApplier();
    }

    /**
     * @param array $payload Decoded webhook body.
     *
     * @return array{status:int, body:array}
     */
    public function handle(array $payload)
    {
        $event = isset($payload['event']) ? (string) $payload['event'] : '';
        $sessionId = self::extractSessionId($payload);

        if ($sessionId === '') {
            Logger::error('Webhook received without a session id.', ['event' => $event]);

            return self::response(400, ['error' => 'Missing sessionId.']);
        }

        Logger::info('Webhook received.', ['event' => $event, 'sessionId' => $sessionId]);

        try {
            // Authoritative state, fetched from Briqpay rather than trusted
            // from the request body.
            $session = $this->sessionManager->read($sessionId);
        } catch (ApiException $e) {
            Logger::error('Webhook session could not be read.', [
                'sessionId' => $sessionId,
                'status' => $e->getStatusCode(),
                'error' => $e->getMerchantMessage(),
            ]);

            // 404 from Briqpay means the id was bogus; anything else may be
            // transient, so ask Briqpay to retry.
            return self::response(
                $e->getStatusCode() === 404 ? 404 : 502,
                ['error' => 'Unable to read the Briqpay session.']
            );
        }

        switch ($event) {
            case self::EVENT_ORDER_STATUS:
                return $this->handleOrderStatus($sessionId, $session);
            case self::EVENT_CAPTURE_STATUS:
                return $this->handleCaptureStatus($sessionId, $session);
            case self::EVENT_REFUND_STATUS:
                return $this->handleRefundStatus($sessionId, $session);
            default:
                Logger::info('Ignoring unsupported webhook event.', ['event' => $event]);

                return self::response(200, ['ignored' => $event]);
        }
    }

    /**
     * Create the order if the buyer never made it back to the shop, otherwise
     * bring the existing order's state in line with Briqpay.
     *
     * @param string $sessionId
     * @param array  $session
     *
     * @return array{status:int, body:array}
     */
    private function handleOrderStatus($sessionId, array $session)
    {
        $cart = $this->resolveCart($sessionId, $session);

        if ($cart === null) {
            return self::response(404, ['error' => 'No cart is associated with this session.']);
        }

        $transaction = OrderCreator::extractTransaction($session);
        $status = isset($transaction['status']) ? (string) $transaction['status'] : '';

        $order = $this->orderCreator->findExistingOrder($cart);

        if ($order === null) {
            // Off-site recovery: the buyer paid but never returned.
            if ($status === '') {
                Logger::info('Session has no transaction yet; nothing to create.', ['sessionId' => $sessionId]);

                return self::response(200, ['pending' => true]);
            }

            try {
                $order = $this->orderCreator->createFromSession($cart, $session);
            } catch (\Exception $e) {
                Logger::error('Webhook failed to create the order.', [
                    'sessionId' => $sessionId,
                    'cartId' => (int) $cart->id,
                    'error' => $e->getMessage(),
                ]);

                return self::response(500, ['error' => 'Order creation failed.']);
            }

            return self::response(200, [
                'created' => true,
                'orderId' => $order !== null ? (int) $order->id : null,
            ]);
        }

        $this->applyStatus($order, $status, $sessionId);

        return self::response(200, [
            'orderId' => (int) $order->id,
            'state' => (int) $order->getCurrentState(),
        ]);
    }

    /**
     * @param string $sessionId
     * @param array  $session
     *
     * @return array{status:int, body:array}
     */
    private function handleCaptureStatus($sessionId, array $session)
    {
        $record = $this->orderRepository->findBySessionId($sessionId);

        if ($record === null) {
            Logger::info('Capture webhook for an unknown order.', ['sessionId' => $sessionId]);

            return self::response(404, ['error' => 'No order is recorded for this session.']);
        }

        $orderId = (int) $record['id_order'];
        $order = new \Order($orderId);

        if (!\Validate::isLoadedObject($order)) {
            return self::response(404, ['error' => 'Order not found.']);
        }

        $captured = self::sumAmounts($session, 'captures');

        // Not a hardcoded 1: a capture the merchant made by hand arrives on
        // this same webhook, and flagging it automatic would tell the order page
        // the opposite of what happened.
        $this->orderRepository->updateByOrderId($orderId, [
            'captured_amount' => $captured,
            'auto_captured' => self::wasAutoCaptured($session) ? 1 : 0,
        ]);

        $this->changeState($order, StateApplier::stateAfterCapture(), $sessionId);

        \Hook::exec('actionBriqpayCaptureNotified', ['order' => $order, 'session' => $session]);

        return self::response(200, ['orderId' => $orderId, 'capturedAmount' => $captured]);
    }

    /**
     * @param string $sessionId
     * @param array  $session
     *
     * @return array{status:int, body:array}
     */
    private function handleRefundStatus($sessionId, array $session)
    {
        $record = $this->orderRepository->findBySessionId($sessionId);

        if ($record === null) {
            return self::response(404, ['error' => 'No order is recorded for this session.']);
        }

        $orderId = (int) $record['id_order'];
        $order = new \Order($orderId);

        if (!\Validate::isLoadedObject($order)) {
            return self::response(404, ['error' => 'Order not found.']);
        }

        $refunded = self::sumAmounts($session, 'refunds');
        $captured = isset($record['captured_amount']) ? (int) $record['captured_amount'] : 0;

        $this->orderRepository->updateByOrderId($orderId, ['refunded_amount' => $refunded]);

        $this->changeState($order, StateApplier::stateAfterRefund($refunded, $captured), $sessionId);

        \Hook::exec('actionBriqpayRefundNotified', ['order' => $order, 'session' => $session]);

        return self::response(200, ['orderId' => $orderId, 'refundedAmount' => $refunded]);
    }

    /**
     * @param \Order $order
     * @param string $status
     * @param string $sessionId
     *
     * @return void
     */
    private function applyStatus(\Order $order, $status, $sessionId)
    {
        if ($status === '') {
            return;
        }

        $this->orderRepository->updateByOrderId((int) $order->id, ['status' => $status]);

        $target = StatusMapper::toOrderState($status);

        if ($target > 0 && (int) $order->getCurrentState() !== $target) {
            $this->changeState($order, $target, $sessionId);
        }
    }

    /**
     * Move the order to a new state.
     *
     * Delegated so that the button on the order page and this webhook cannot
     * drift apart: whichever reports the operation first moves the order, and
     * the other finds it already there and does nothing.
     *
     * @param \Order $order
     * @param int    $stateId
     * @param string $sessionId
     *
     * @return void
     */
    private function changeState(\Order $order, $stateId, $sessionId)
    {
        $this->stateApplier->apply($order, $stateId, $sessionId);
    }

    /**
     * Find the cart a session belongs to, preferring our own mapping and
     * falling back to the reference Briqpay carries.
     *
     * @param string $sessionId
     * @param array  $session
     *
     * @return \Cart|null
     */
    private function resolveCart($sessionId, array $session)
    {
        $cartId = 0;

        $stored = $this->sessionRepository->findBySessionId($sessionId);
        if ($stored !== null) {
            $cartId = (int) $stored['id_cart'];
        }

        if ($cartId <= 0 && isset($session['references']['cartId'])) {
            $cartId = (int) $session['references']['cartId'];
        }

        if ($cartId <= 0) {
            return null;
        }

        $cart = new \Cart($cartId);

        return \Validate::isLoadedObject($cart) ? $cart : null;
    }

    /**
     * Whether the settlement was Briqpay's doing rather than the merchant's.
     *
     * @param array $session
     *
     * @return bool
     */
    public static function wasAutoCaptured(array $session)
    {
        $transaction = OrderCreator::extractTransaction($session);

        if (OrderCreator::isAutoCaptured($transaction)) {
            return true;
        }

        $captures = isset($session['data']['captures']) && is_array($session['data']['captures'])
            ? $session['data']['captures']
            : [];

        foreach ($captures as $capture) {
            if (is_array($capture) && !empty($capture['autoCaptured'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Total of the approved entries in a session's capture or refund list.
     *
     * @param array  $session
     * @param string $key
     *
     * @return int Minor units.
     */
    public static function sumAmounts(array $session, $key)
    {
        return self::sumEntries($session, $key, true);
    }

    /**
     * Total of the entries Briqpay has accepted but not yet settled.
     *
     * A capture can sit pending for a while, and during that time it counts for
     * nothing in sumAmounts() -- correctly, since the money has not moved. But
     * the operation has been sent, so treating the order as though nothing were
     * happening offers the merchant a Capture button for a capture that is
     * already under way, and a second press captures twice.
     *
     * @param array  $session
     * @param string $key     Either 'captures' or 'refunds'.
     *
     * @return int Minor units.
     */
    public static function sumPendingAmounts(array $session, $key)
    {
        return self::sumEntries($session, $key, false);
    }

    /**
     * @param array  $session
     * @param string $key
     * @param bool   $approved Sum the settled entries, or the pending ones.
     *
     * @return int
     */
    private static function sumEntries(array $session, $key, $approved)
    {
        $entries = isset($session['data'][$key]) && is_array($session['data'][$key])
            ? $session['data'][$key]
            : [];

        $total = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Briqpay omits the status on an entry it has already accepted.
            $status = isset($entry['status']) ? (string) $entry['status'] : 'approved';

            $matches = $approved
                ? StatusMapper::isApproved($status)
                : StatusMapper::isPending($status);

            if (!$matches) {
                continue;
            }

            if (isset($entry['amountIncVat'])) {
                $total += (int) $entry['amountIncVat'];
            } elseif (isset($entry['amount'])) {
                $total += (int) $entry['amount'];
            }
        }

        return $total;
    }

    /**
     * Briqpay puts the session id at the top level; be liberal about where we
     * look for it so a payload shape change does not silently drop events.
     *
     * @param array $payload
     *
     * @return string
     */
    public static function extractSessionId(array $payload)
    {
        foreach (['sessionId', 'session_id', 'id'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        return '';
    }

    /**
     * @param int   $status
     * @param array $body
     *
     * @return array{status:int, body:array}
     */
    private static function response($status, array $body)
    {
        return ['status' => (int) $status, 'body' => $body];
    }
}
