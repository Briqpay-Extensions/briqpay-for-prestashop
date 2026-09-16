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
 * Pushes cart changes made during checkout into the open Briqpay session.
 *
 * The session is only PATCHed when the cart data has genuinely diverged, so
 * repeated calls from the storefront are cheap.
 */
class Briqpay_Payment_ModuleUpdateCartModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart)) {
            $this->respond(400, ['error' => true, 'message' => 'Cart is missing or invalid.']);
        }

        $stored = $this->module->getBriqpayServices()->getSessionRepository()->findByCartId((int) $cart->id);

        if ($stored === null) {
            $this->respond(404, ['error' => true, 'message' => 'No Briqpay session is open for this cart.']);

            return;
        }

        try {
            $this->module->getBriqpayServices()->getSessionManager()->syncIfChanged(
                $this->context,
                (string) $stored['session_id'],
                isset($stored['fingerprint']) ? (string) $stored['fingerprint'] : ''
            );
        } catch (ApiException $e) {
            Logger::error('Could not update the session from the cart.', [
                'cartId' => (int) $cart->id,
                'error' => $e->getMerchantMessage(),
            ]);

            $this->respond(502, ['error' => true, 'message' => 'Could not update the Briqpay session.']);

            return;
        }

        $this->respond(200, ['success' => true]);
    }

    public function initContent()
    {
        $this->postProcess();
    }

    /**
     * @param int   $status
     * @param array $body
     *
     * @return void
     */
    private function respond($status, array $body)
    {
        header('Content-Type: application/json', true, (int) $status);
        echo json_encode($body);
        exit;
    }
}
