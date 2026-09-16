<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where Briqpay returns the shopper once payment is complete.
 *
 * Creates the PrestaShop order if the webhook has not beaten the shopper back,
 * then forwards to the standard order-confirmation page.
 */
class Briqpay_Payment_ModuleValidationModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    public function postProcess()
    {
        $sessionId = trim((string) Tools::getValue('briqpay_session'));

        if ($sessionId === '') {
            Logger::error('Validation was called without a session id.');
            $this->redirectToCart('briqpay_error=missing_session');
        }

        try {
            $session = $this->module->getBriqpayServices()->getSessionManager()->read($sessionId);
        } catch (ApiException $e) {
            Logger::error('Validation could not read the session.', [
                'sessionId' => $sessionId,
                'error' => $e->getMerchantMessage(),
            ]);
            $this->redirectToCart('briqpay_error=session_unavailable');

            return;
        }

        $cart = $this->resolveCart($sessionId, $session);

        if ($cart === null) {
            Logger::error('Validation could not resolve the cart.', ['sessionId' => $sessionId]);
            $this->redirectToCart('briqpay_error=cart_not_found');

            return;
        }

        try {
            $order = $this->module->getBriqpayServices()->getOrderCreator()->createFromSession($cart, $session);
        } catch (Exception $e) {
            Logger::error('Validation failed to create the order.', [
                'sessionId' => $sessionId,
                'cartId' => (int) $cart->id,
                'error' => $e->getMessage(),
            ]);
            $this->redirectToCart('briqpay_error=order_failed');

            return;
        }

        if ($order === null) {
            $this->redirectToCart('briqpay_error=order_failed');

            return;
        }

        $this->module->getBriqpayServices()->getSessionRepository()->deleteByCartId((int) $cart->id);

        $customer = new Customer((int) $order->id_customer);

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => $customer->secure_key,
        ]));
    }

    /**
     * Prefer the module's own cart mapping; fall back to the reference stored
     * on the Briqpay session.
     *
     * @param string $sessionId
     * @param array  $session
     *
     * @return Cart|null
     */
    private function resolveCart($sessionId, array $session)
    {
        $cartId = 0;

        $stored = $this->module->getBriqpayServices()->getSessionRepository()->findBySessionId($sessionId);
        if ($stored !== null) {
            $cartId = (int) $stored['id_cart'];
        }

        if ($cartId <= 0 && isset($session['references']['cartId'])) {
            $cartId = (int) $session['references']['cartId'];
        }

        if ($cartId <= 0) {
            return null;
        }

        $cart = new Cart($cartId);

        return Validate::isLoadedObject($cart) ? $cart : null;
    }

    /**
     * @param string $query
     *
     * @return void
     */
    private function redirectToCart($query)
    {
        Tools::redirect($this->context->link->getPageLink('cart', true, null, ['action' => 'show']) . '&' . $query);
        exit;
    }
}
