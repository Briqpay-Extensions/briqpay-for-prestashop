<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Order;

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Support\Logger;
use Briqpay\Payment\Webhook\WebhookHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Brings the stored payment record back in line with Briqpay.
 *
 * Captures and refunds are normally learned from webhooks, which makes the
 * order page only as accurate as the last delivery. A webhook that is delayed
 * leaves it briefly wrong; one that is lost -- no public address in
 * development, an outage in production -- leaves it wrong for good, showing a
 * capture that happened as though it never did, with nothing the merchant can
 * press to correct it.
 *
 * Re-reading the session when the panel is rendered makes Briqpay the authority
 * and the stored row a cache. It costs one GET per order page view, which an
 * admin page can afford.
 */
class PaymentSync
{
    /** @var SessionManager */
    private $sessionManager;

    /** @var OrderRepository */
    private $orderRepository;

    public function __construct(SessionManager $sessionManager, ?OrderRepository $orderRepository = null)
    {
        $this->sessionManager = $sessionManager;
        $this->orderRepository = $orderRepository !== null ? $orderRepository : new OrderRepository();
    }

    /**
     * Refresh the stored record from the live session.
     *
     * Never throws: a payment page that cannot reach Briqpay should still show
     * what is known rather than an error.
     *
     * @param int $orderId
     *
     * @return array|null The refreshed record, or the stored one unchanged.
     */
    public function refresh($orderId)
    {
        $record = $this->orderRepository->findByOrderId((int) $orderId);

        if ($record === null || empty($record['session_id'])) {
            return $record;
        }

        $record += ['pending_capture' => 0, 'pending_refund' => 0];

        try {
            $session = $this->sessionManager->read((string) $record['session_id']);
        } catch (ApiException $e) {
            Logger::info('Could not refresh the payment from Briqpay; showing the stored record.', [
                'orderId' => (int) $orderId,
                'error' => $e->getMerchantMessage(),
            ]);

            return $record;
        }

        $changes = $this->diff($record, $session);

        if (!empty($changes)) {
            $this->orderRepository->updateByOrderId((int) $orderId, $changes);

            Logger::info('Refreshed the payment record from Briqpay.', [
                'orderId' => (int) $orderId,
                'changed' => array_keys($changes),
            ]);

            $record = array_merge($record, $changes);
        }

        return array_merge($record, self::inFlight($session));
    }

    /**
     * Amounts Briqpay has accepted but not yet settled.
     *
     * Deliberately not stored: they are true only for as long as the read that
     * produced them, and a stale "capture in progress" left in the database
     * would block the order for good. They are carried on the returned record
     * under keys the repository does not recognise, so nothing writes them.
     *
     * @param array $session
     *
     * @return array{pending_capture:int, pending_refund:int}
     */
    public static function inFlight(array $session)
    {
        return [
            'pending_capture' => WebhookHandler::sumPendingAmounts($session, 'captures'),
            'pending_refund' => WebhookHandler::sumPendingAmounts($session, 'refunds'),
        ];
    }

    /**
     * Fields whose live value differs from what is stored.
     *
     * Only differences are returned so an unchanged payment costs no write.
     *
     * @param array $record
     * @param array $session
     *
     * @return array<string, mixed>
     */
    public function diff(array $record, array $session)
    {
        $transaction = OrderCreator::extractTransaction($session);

        $live = [
            'status' => isset($transaction['status']) ? (string) $transaction['status'] : (string) $record['status'],
            'captured_amount' => WebhookHandler::sumAmounts($session, 'captures'),
            'refunded_amount' => WebhookHandler::sumAmounts($session, 'refunds'),
            'auto_captured' => WebhookHandler::wasAutoCaptured($session) ? 1 : 0,
        ];

        // The PSP is only known once a payment method has been chosen, so it can
        // still be empty on a record written at order creation.
        foreach (['pspDisplayName' => 'psp_display_name', 'pspIntegrationName' => 'psp_name', 'reservationId' => 'reservation_id'] as $from => $to) {
            if (!empty($transaction[$from])) {
                $live[$to] = (string) $transaction[$from];
            }
        }

        $changes = [];

        foreach ($live as $field => $value) {
            $current = isset($record[$field]) ? $record[$field] : null;

            // Stored values arrive from the database as strings.
            if ((string) $current !== (string) $value) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }
}
