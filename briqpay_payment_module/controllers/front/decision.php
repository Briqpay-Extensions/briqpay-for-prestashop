<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Answers Briqpay's decision step.
 *
 * Briqpay pauses just before taking the money and asks the shop whether the
 * purchase may proceed. This is the last point at which a cart that drifted
 * out of sync can be stopped.
 */
class Briqpay_Payment_ModuleDecisionModuleFrontController extends ModuleFrontController
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
            Logger::error('Decision called without a usable cart.');

            $this->respond(400, ['error' => true, 'message' => 'Cart is missing or invalid.']);
        }

        $input = $this->readJsonBody();

        $sessionId = isset($input['sessionId'])
            ? trim((string) $input['sessionId'])
            : trim((string) Tools::getValue('sessionId'));

        if ($sessionId === '') {
            Logger::error('Decision called without a session id.', [
                'cartId' => (int) $cart->id,
                'input' => array_keys($input),
            ]);

            $this->respond(400, ['error' => true, 'message' => 'Missing session id.']);
        }

        $stored = $this->module->getBriqpayServices()->getSessionRepository()->findByCartId((int) $cart->id);

        // The session id arrives from the browser, so only accept it if it is
        // the one this shop actually opened for this cart.
        if ($stored === null || !hash_equals((string) $stored['session_id'], $sessionId)) {
            Logger::error('Decision requested for a session that does not belong to this cart.', [
                'cartId' => (int) $cart->id,
                'sessionId' => $sessionId,
            ]);

            $this->respond(403, ['error' => true, 'message' => 'This session does not belong to the current cart.']);
        }

        // The terms gate lives here rather than in the storefront so it cannot
        // be stepped around by editing the page: Briqpay pauses for this answer
        // before taking any money, and a reject stops the purchase outright.
        if (Settings::getBool(Settings::ENFORCE_TERMS) && !$this->hasAcceptedTerms($input)) {
            $message = $this->trans(
                'Please accept the terms and conditions to continue.',
                [],
                'Modules.Briqpaypaymentmodule.Shop'
            );

            Logger::error('Purchase rejected: terms not accepted.', ['sessionId' => $sessionId]);

            $this->rejectAndRespond($sessionId, $message);
        }

        $sessionManager = $this->module->getBriqpayServices()->getSessionManager();

        try {
            $session = $sessionManager->read($sessionId);
        } catch (ApiException $e) {
            Logger::error('Decision could not read the session.', [
                'sessionId' => $sessionId,
                'error' => $e->getMerchantMessage(),
            ]);

            $this->respond(502, ['error' => true, 'message' => 'Could not reach Briqpay.']);

            return;
        }

        Hook::exec('actionBriqpayDecisionData', [
            'sessionData' => &$session,
            'context' => $this->context,
        ]);

        $result = $this->module->getBriqpayServices()->getSessionValidator()->validate($cart, $session);

        try {
            $sessionManager->decide($sessionId, $result->isValid(), $result->getMessage());
        } catch (ApiException $e) {
            Logger::error('Could not submit the decision.', [
                'sessionId' => $sessionId,
                'error' => $e->getMerchantMessage(),
            ]);

            $this->respond(502, ['error' => true, 'message' => 'Could not submit the decision to Briqpay.']);

            return;
        }

        if ($result->isValid()) {
            $this->respond(200, ['success' => true]);
        }

        Logger::error('Purchase rejected at the decision step.', [
            'sessionId' => $sessionId,
            'reason' => $result->getMessage(),
        ]);

        $this->respond(200, [
            'error' => true,
            'rejected' => true,
            'message' => $result->getMessage(),
        ]);
    }

    public function initContent()
    {
        $this->postProcess();
    }

    /**
     * The storefront posts JSON, which PHP does not decode into $_POST, so
     * Tools::getValue() cannot see any of it.
     *
     * @return array
     */
    private function readJsonBody()
    {
        $raw = Tools::file_get_contents('php://input');

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array $input
     *
     * @return bool
     */
    private function hasAcceptedTerms(array $input)
    {
        // Absent means the storefront did not report either way; only an
        // explicit false blocks the purchase.
        if (!array_key_exists('termsAccepted', $input)) {
            return true;
        }

        return (bool) $input['termsAccepted'];
    }

    /**
     * Tell Briqpay to stop, then report why to the storefront.
     *
     * @param string $sessionId
     * @param string $message
     *
     * @return void
     */
    private function rejectAndRespond($sessionId, $message)
    {
        try {
            $this->module->getBriqpayServices()->getSessionManager()->decide($sessionId, false, $message);
        } catch (ApiException $e) {
            Logger::error('Could not submit the rejection.', [
                'sessionId' => $sessionId,
                'error' => $e->getMerchantMessage(),
            ]);
        }

        $this->respond(200, [
            'error' => true,
            'rejected' => true,
            'message' => $message,
        ]);
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
