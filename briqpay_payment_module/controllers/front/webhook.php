<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Endpoint Briqpay posts asynchronous status notifications to.
 *
 * The request body is untrusted. Only the session id is taken from it; the
 * handler then re-reads the session over the authenticated Briqpay API and
 * acts on that, so the shop never changes an order based on the contents of an
 * unauthenticated POST.
 */
class Briqpay_Payment_ModuleWebhookModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $content_only = true;

    /**
     * Keep accepting notifications while the shop is in maintenance mode.
     *
     * A payment that completed before the shop was closed still has to be
     * recorded; serving Briqpay the maintenance page would lose the event.
     *
     * @return void
     */
    protected function displayMaintenancePage()
    {
    }

    public function postProcess()
    {
        $raw = Tools::file_get_contents('php://input');
        $payload = json_decode((string) $raw, true);

        if (!is_array($payload)) {
            Logger::error('Webhook body was not valid JSON.');
            $this->respond(400, ['error' => 'Invalid JSON body.']);
        }

        try {
            $result = $this->module->getBriqpayServices()->getWebhookHandler()->handle($payload);
        } catch (Exception $e) {
            Logger::error('Unhandled webhook failure.', ['error' => $e->getMessage()]);
            $this->respond(500, ['error' => 'Internal error.']);

            return;
        }

        $this->respond($result['status'], $result['body']);
    }

    /**
     * Webhooks must never be answered with an HTML page.
     *
     * @return void
     */
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
