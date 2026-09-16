<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Order;

use Briqpay\Payment\Builder\CartBuilder;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Support\Lock;
use Briqpay\Payment\Support\Logger;
use Briqpay\Payment\Webhook\WebhookHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Turns a completed Briqpay session into a PrestaShop order.
 *
 * Both the buyer's return to the shop and the order_status webhook land here,
 * possibly at the same time, so the whole operation is guarded by a per-cart
 * lock and is safe to call more than once.
 */
class OrderCreator
{
    /** @var \PaymentModule */
    private $module;

    /** @var SessionManager */
    private $sessionManager;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var CartBuilder */
    private $cartBuilder;

    public function __construct(
        \PaymentModule $module,
        SessionManager $sessionManager,
        ?OrderRepository $orderRepository = null,
        ?CartBuilder $cartBuilder = null
    ) {
        $this->module = $module;
        $this->sessionManager = $sessionManager;
        $this->orderRepository = $orderRepository !== null ? $orderRepository : new OrderRepository();
        $this->cartBuilder = $cartBuilder !== null ? $cartBuilder : new CartBuilder();
    }

    /**
     * Create the order for a cart if it does not exist yet.
     *
     * @param \Cart $cart
     * @param array $session Briqpay session resource.
     *
     * @return \Order|null The order, or null when it could not be created.
     *
     * @throws \Exception
     */
    public function createFromSession(\Cart $cart, array $session)
    {
        $lock = Lock::forCart((int) $cart->id);

        if (!$lock->acquire(10)) {
            Logger::error('Timed out waiting for the cart lock.', ['cartId' => (int) $cart->id]);

            return $this->findExistingOrder($cart);
        }

        try {
            $existing = $this->findExistingOrder($cart);
            if ($existing !== null) {
                Logger::debug('Order already exists for cart.', [
                    'cartId' => (int) $cart->id,
                    'orderId' => (int) $existing->id,
                ]);

                return $existing;
            }

            return $this->doCreate($cart, $session);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param \Cart $cart
     * @param array $session
     *
     * @return \Order
     *
     * @throws \Exception
     */
    private function doCreate(\Cart $cart, array $session)
    {
        $transaction = self::extractTransaction($session);
        $status = isset($transaction['status']) ? (string) $transaction['status'] : '';
        $customer = new \Customer((int) $cart->id_customer);

        if (!\Validate::isLoadedObject($customer)) {
            throw new \Exception('Cannot create an order for cart ' . (int) $cart->id . ': the customer is missing.');
        }

        // PrestaShop caches cart totals aggressively; a stale total here would
        // be written onto the order.
        \Cache::clean('objectmodel_cart_' . (int) $cart->id . '*');

        $total = (float) $cart->getOrderTotal(true, \Cart::BOTH);
        $paymentMethod = isset($transaction['pspDisplayName']) && $transaction['pspDisplayName'] !== ''
            ? (string) $transaction['pspDisplayName']
            : (string) $this->module->displayName;

        $this->module->validateOrder(
            (int) $cart->id,
            StatusMapper::toOrderState($status),
            $total,
            $paymentMethod,
            null,
            ['transaction_id' => isset($session['sessionId']) ? (string) $session['sessionId'] : ''],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        $orderId = (int) \Order::getOrderByCartId((int) $cart->id);
        if ($orderId <= 0) {
            throw new \Exception('PrestaShop did not create an order for cart ' . (int) $cart->id . '.');
        }

        $order = new \Order($orderId);

        Logger::info('Created order from Briqpay session.', [
            'orderId' => $orderId,
            'reference' => $order->reference,
            'cartId' => (int) $cart->id,
            'status' => $status,
        ]);

        $this->persistPaymentRecord($cart, $order, $session, $transaction);

        if (!empty($session['sessionId'])) {
            $this->sessionManager->updateReferences(
                (string) $session['sessionId'],
                (string) $order->reference,
                $orderId,
                (int) $cart->id
            );
        }

        \Hook::exec('actionBriqpayOrderCreated', [
            'order' => $order,
            'cart' => $cart,
            'session' => $session,
        ]);

        return $order;
    }

    /**
     * @param \Cart  $cart
     * @param \Order $order
     * @param array  $session
     * @param array  $transaction
     *
     * @return void
     */
    private function persistPaymentRecord(\Cart $cart, \Order $order, array $session, array $transaction)
    {
        $currency = new \Currency((int) $cart->id_currency);
        $company = isset($session['data']['company']) ? $session['data']['company'] : [];

        try {
            $this->orderRepository->save([
                'id_order' => (int) $order->id,
                'id_cart' => (int) $cart->id,
                'session_id' => isset($session['sessionId']) ? (string) $session['sessionId'] : '',
                'order_reference' => (string) $order->reference,
                'status' => isset($transaction['status']) ? (string) $transaction['status'] : '',
                'payment_method' => isset($transaction['pspIntegrationName']) ? (string) $transaction['pspIntegrationName'] : '',
                'psp_name' => isset($transaction['pspIntegrationName']) ? (string) $transaction['pspIntegrationName'] : '',
                'psp_display_name' => isset($transaction['pspDisplayName']) ? (string) $transaction['pspDisplayName'] : '',
                'reservation_id' => isset($transaction['reservationId']) ? (string) $transaction['reservationId'] : '',
                'currency' => (string) $currency->iso_code,
                'customer_type' => isset($session['customerType']) ? (string) $session['customerType'] : '',
                'company_name' => isset($company['name']) ? (string) $company['name'] : '',
                'company_cin' => isset($company['cin']) ? (string) $company['cin'] : '',
                'merchant_id' => SessionManager::extractMerchantId($session),
                'total_amount' => $this->cartBuilder->getAmountIncVat($cart),
                // Briqpay says at order time whether this payment method
                // settles by itself, and a session can already carry captures
                // by the time the order is created. Taking both from the
                // session rather than waiting for the capture webhook keeps the
                // order page from offering a Capture button for a payment that
                // captures itself -- and from reporting nothing captured on one
                // that already is.
                'captured_amount' => WebhookHandler::sumAmounts($session, 'captures'),
                'refunded_amount' => WebhookHandler::sumAmounts($session, 'refunds'),
                'auto_captured' => self::isAutoCaptured($transaction) ? 1 : 0,
            ]);
        } catch (\Exception $e) {
            // The order itself is valid; losing the Briqpay side record is
            // recoverable and must not roll the purchase back.
            Logger::error('Failed to store the Briqpay payment record.', [
                'orderId' => (int) $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param \Cart $cart
     *
     * @return \Order|null
     */
    public function findExistingOrder(\Cart $cart)
    {
        $orderId = (int) \Order::getOrderByCartId((int) $cart->id);

        if ($orderId <= 0) {
            return null;
        }

        $order = new \Order($orderId);

        return \Validate::isLoadedObject($order) ? $order : null;
    }

    /**
     * Whether Briqpay settles this payment without the merchant capturing.
     *
     * @param array $transaction
     *
     * @return bool
     */
    public static function isAutoCaptured(array $transaction)
    {
        return !empty($transaction['autoCaptureEnabled']);
    }

    /**
     * Briqpay returns an array of transactions; the payment module only ever
     * creates one per session.
     *
     * @param array $session
     *
     * @return array
     */
    public static function extractTransaction(array $session)
    {
        if (isset($session['data']['transactions'][0]) && is_array($session['data']['transactions'][0])) {
            return $session['data']['transactions'][0];
        }

        return [];
    }
}
