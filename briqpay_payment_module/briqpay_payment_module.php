<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Autoloader;
use Briqpay\Payment\Builder\Money;
use Briqpay\Payment\Builder\RefundLineMapper;
use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Hpp\Flow;
use Briqpay\Payment\Hpp\HostedPageManager;
use Briqpay\Payment\Install\Installer;
use Briqpay\Payment\Order\OrderManager;
use Briqpay\Payment\Order\StatusMapper;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\ServiceContainer;
use Briqpay\Payment\Support\Logger;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/src/Autoloader.php';
Autoloader::register(dirname(__FILE__) . '/src');

class Briqpay_Payment_Module extends PaymentModule
{
    /** @var ServiceContainer|null */
    private $container;

    public function __construct()
    {
        $this->name = 'briqpay_payment_module';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.1';
        $this->author = 'Briqpay';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->controllers = ['validation', 'decision', 'updatecart', 'webhook'];

        parent::__construct();

        $this->displayName = $this->trans('Briqpay Payments', [], 'Modules.Briqpaypaymentmodule.Admin');
        $this->description = $this->trans(
            'Accept invoice, card, direct bank and BNPL payments through a single Briqpay integration.',
            [],
            'Modules.Briqpaypaymentmodule.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall Briqpay? Your existing payment records will be kept.',
            [],
            'Modules.Briqpaypaymentmodule.Admin'
        );

        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
    }

    /**
     * The module's own service container.
     *
     * Deliberately not called getContainer(): PrestaShop 8 declares
     * ModuleCore::getContainer(): ContainerInterface, and an incompatible
     * override there is a fatal error at class-load time.
     *
     * @return ServiceContainer
     */
    public function getBriqpayServices()
    {
        if ($this->container === null) {
            $this->container = new ServiceContainer($this);
        }

        return $this->container;
    }

    /* ---------------------------------------------------------------------
     * Install / uninstall
     * ------------------------------------------------------------------ */

    public function install()
    {
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->trans(
                'The cURL PHP extension must be enabled to install Briqpay.',
                [],
                'Modules.Briqpaypaymentmodule.Admin'
            );

            return false;
        }

        if (!parent::install()) {
            return false;
        }

        $installer = new Installer($this);

        if (!$installer->install()) {
            $this->_errors[] = $this->trans(
                'Briqpay could not create its database tables or register its hooks.',
                [],
                'Modules.Briqpaypaymentmodule.Admin'
            );

            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $installer = new Installer($this);

        return $installer->uninstall() && parent::uninstall();
    }

    /**
     * Briqpay is only offered when it is actually usable.
     *
     * @return bool
     */
    public function isAvailable()
    {
        if (!Settings::isConfigured()) {
            return false;
        }

        return (bool) $this->active;
    }

    /* ---------------------------------------------------------------------
     * Front office
     * ------------------------------------------------------------------ */

    /**
     * Register the checkout assets on the payment step.
     *
     * @return void
     */
    public function hookHeader()
    {
        if (!$this->isAvailable() || !$this->isCheckoutPage()) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'briqpay-checkout',
            'modules/' . $this->name . '/views/css/briqpay_checkout.css',
            ['media' => 'all', 'priority' => 150]
        );

        $this->context->controller->registerJavascript(
            'briqpay-checkout',
            'modules/' . $this->name . '/views/js/briqpay_checkout.js',
            ['position' => 'bottom', 'priority' => 150]
        );

        Media::addJsDef([
            'briqpayConfig' => [
                'updateCartUrl' => $this->context->link->getModuleLink($this->name, 'updatecart', [], true),
                'decisionUrl' => $this->context->link->getModuleLink($this->name, 'decision', [], true),
                'validationUrl' => $this->context->link->getModuleLink($this->name, 'validation', [], true),
                'takeoverPaymentStep' => Settings::getBool(Settings::TAKEOVER_PAYMENT_STEP),
                'enforceTerms' => Settings::getBool(Settings::ENFORCE_TERMS),
                'moduleName' => $this->name,
                'translations' => [
                    'termsRequired' => $this->trans(
                        'Please accept the terms and conditions to continue.',
                        [],
                        'Modules.Briqpaypaymentmodule.Shop'
                    ),
                    'genericError' => $this->trans(
                        'Something went wrong while updating your payment. Please refresh the page.',
                        [],
                        'Modules.Briqpaypaymentmodule.Shop'
                    ),
                    'completeInIframe' => $this->trans(
                        'Please complete the payment in the form above.',
                        [],
                        'Modules.Briqpaypaymentmodule.Shop'
                    ),
                ],
            ],
        ]);
    }

    /**
     * Render Briqpay as a PrestaShop payment option.
     *
     * The iframe is delivered as the option's form, so it appears inside the
     * native checkout's payment step instead of replacing the whole page as
     * the 1.x module did.
     *
     * @param array $params
     *
     * @return PaymentOption[]|array
     */
    public function hookPaymentOptions(array $params)
    {
        if (!$this->isAvailable()) {
            return [];
        }

        /** @var Cart $cart */
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;

        if (!Validate::isLoadedObject($cart)) {
            return [];
        }

        if (!$this->checkCurrency($cart)) {
            // PrestaShop records which currencies a payment module accepts when
            // the module is installed. A currency added later is not on that
            // list, so the module disappears from checkout with nothing said --
            // which looks like the module being broken rather than unconfigured.
            $currency = new Currency((int) $cart->id_currency);

            Logger::info('Declined: this currency is not enabled for the module.', [
                'currency' => $currency->iso_code,
                'hint' => 'Payment > Preferences, or reinstall the module',
            ]);

            return [];
        }

        try {
            $session = $this->getBriqpayServices()->getSessionManager()->getOrCreateForCart($this->context);
        } catch (ApiException $e) {
            Logger::error('Could not prepare the checkout session.', [
                'cartId' => (int) $cart->id,
                'error' => $e->getMerchantMessage(),
            ]);

            // Never break the payment step: just do not offer Briqpay.
            return [];
        }

        if (empty($session['htmlSnippet'])) {
            Logger::error('Briqpay returned a session without an HTML snippet.', [
                'sessionId' => isset($session['sessionId']) ? $session['sessionId'] : null,
            ]);

            return [];
        }

        $this->context->smarty->assign([
            'briqpaySnippet' => $session['htmlSnippet'],
            'briqpaySessionId' => isset($session['sessionId']) ? $session['sessionId'] : '',
            'briqpayFormAction' => $this->context->link->getPageLink('order', true, null, ['step' => 3]),
            'briqpayTakeover' => Settings::getBool(Settings::TAKEOVER_PAYMENT_STEP),
        ]);

        $takeover = Settings::getBool(Settings::TAKEOVER_PAYMENT_STEP);

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->getDisplayName())
            ->setForm($this->fetch('module:' . $this->name . '/views/templates/front/payment_iframe.tpl'))
            ->setAdditionalInformation('');

        // In takeover mode the iframe presents every method and carries its own
        // Briqpay branding, so a call-to-action row and a second logo above it
        // are just noise. The label cannot be omitted -- PrestaShop requires
        // one -- but the logo can, which keeps it from flashing up before the
        // stylesheet has hidden the row.
        if (!$takeover) {
            $logo = _PS_MODULE_DIR_ . $this->name . '/logo.png';
            if (is_file($logo)) {
                $option->setLogo(Media::getMediaPath($logo));
            }
        }

        return [$option];
    }

    /**
     * Marks the payment step so the stylesheet can collapse the native option
     * list down to the Briqpay iframe.
     *
     * @return string
     */
    public function hookDisplayPaymentTop()
    {
        if (!$this->isAvailable() || !Settings::getBool(Settings::TAKEOVER_PAYMENT_STEP)) {
            return '';
        }

        return '<div id="briqpay-takeover-marker" data-briqpay-takeover="1"></div>';
    }

    /**
     * Keep the Briqpay session in step with cart changes made outside the
     * checkout page.
     *
     * @param array $params
     *
     * @return void
     */
    public function hookActionCartUpdateQuantityBefore(array $params)
    {
        if (!$this->isAvailable() || empty($params['cart'])) {
            return;
        }

        $cart = $params['cart'];
        $stored = $this->getBriqpayServices()->getSessionRepository()->findByCartId((int) $cart->id);

        if ($stored === null) {
            return;
        }

        // Invalidate the fingerprint so the next read is guaranteed to PATCH.
        $this->getBriqpayServices()->getSessionRepository()->updateFingerprint((int) $cart->id, '');
    }

    /**
     * @return string
     */
    public function getDisplayName()
    {
        $configured = trim(Settings::getString(Settings::DISPLAY_NAME));

        return $configured !== '' ? $configured : $this->displayName;
    }

    /**
     * Active shop currencies that this module is not enabled for.
     *
     * These are almost always currencies added after the module was installed:
     * PrestaShop does not backfill the association, so the module quietly stops
     * being offered to anyone shopping in them.
     *
     * @return array<int, string>
     */
    private function getCurrenciesNotEnabled()
    {
        $allowed = [];
        foreach (Currency::getPaymentCurrencies($this->id) as $row) {
            $allowed[(int) $row['id_currency']] = true;
        }

        $missing = [];
        foreach (Currency::getCurrencies(false, true) as $currency) {
            if (!isset($allowed[(int) $currency['id_currency']])) {
                $missing[] = (string) $currency['iso_code'];
            }
        }

        return $missing;
    }

    /**
     * @param Cart $cart
     *
     * @return bool
     */
    public function checkCurrency(Cart $cart)
    {
        $currency = new Currency((int) $cart->id_currency);

        foreach (Currency::getPaymentCurrencies($this->id) as $allowed) {
            if ((int) $currency->id === (int) $allowed['id_currency']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    private function isCheckoutPage()
    {
        if (!isset($this->context->controller)) {
            return false;
        }

        $page = method_exists($this->context->controller, 'getPageName')
            ? $this->context->controller->getPageName()
            : '';

        return in_array($page, ['checkout', 'order'], true);
    }

    /* ---------------------------------------------------------------------
     * Back office: payment operations
     * ------------------------------------------------------------------ */

    /**
     * Drive capture, refund and cancel from PrestaShop order state changes.
     *
     * The 1.x module implemented `hookUpdateOrderStatus` while registering
     * `actionOrderStatusUpdate`; PrestaShop calls neither of those for a
     * committed state change, so captures and refunds never ran at all.
     *
     * @param array $params
     *
     * @return void
     */
    public function hookActionOrderStatusPostUpdate(array $params)
    {
        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }

        $order = new Order((int) $params['id_order']);

        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return;
        }

        $newStateId = (int) $params['newOrderStatus']->id;
        $manager = $this->getBriqpayServices()->getOrderManager();

        // What the payment has already had done to it.
        //
        // A state change is as often an effect of a payment operation as a
        // cause of one: capturing or refunding moves the order itself, and
        // PrestaShop then calls this hook. Acting on that without looking means
        // immediately attempting the very operation that caused it -- refunding
        // an order that was just refunded -- which Briqpay rejects and which
        // lands on the order as "Briqpay error: refunding would exceed the
        // captured amount", on every single refund.
        //
        // So each branch asks whether there is anything left to do. A merchant
        // who moves an order to Refunded by hand still gets the refund; one
        // whose order arrived there by refunding gets silence.
        $record = $this->getBriqpayServices()->getOrderRepository()->findByOrderId((int) $order->id);

        if ($record === null) {
            return;
        }

        try {
            if (Settings::getBool(Settings::CAPTURE_ON_STATE)
                && $this->isCaptureState($newStateId)
                && OrderManager::getCapturableAmount($record, $order) > 0
            ) {
                $manager->capture($order);
                $this->logOrderMessage($order, 'Briqpay: payment captured in full.');
            } elseif (Settings::getBool(Settings::REFUND_ON_STATE)
                && $newStateId === StatusMapper::getRefundedState()
                && OrderManager::getRefundableAmount($record) > 0
            ) {
                $manager->refund($order);
                $this->logOrderMessage($order, 'Briqpay: payment refunded in full.');
            } elseif (Settings::getBool(Settings::CANCEL_ON_STATE)
                && $newStateId === StatusMapper::getCancelledState()
                && (int) $record['captured_amount'] === 0
                && (string) $record['status'] !== 'cancelled'
            ) {
                $manager->cancel($order);
                $this->logOrderMessage($order, 'Briqpay: authorisation cancelled.');
            }
        } catch (ApiException $e) {
            Logger::error('Payment operation triggered by a state change failed.', [
                'orderId' => (int) $order->id,
                'state' => $newStateId,
                'error' => $e->getMerchantMessage(),
            ]);

            $this->logOrderMessage($order, 'Briqpay error: ' . $e->getMerchantMessage());

            // Surface the failure to the employee without rolling back the
            // state change, which PrestaShop has already committed.
            if (isset($this->context->controller) && property_exists($this->context->controller, 'errors')) {
                $this->context->controller->errors[] = $this->trans(
                    'Briqpay could not complete the payment operation: %error%',
                    ['%error%' => $e->getMerchantMessage()],
                    'Modules.Briqpaypaymentmodule.Admin'
                );
            }
        }
    }

    /**
     * Mirror PrestaShop partial refunds (credit slips) to Briqpay.
     *
     * @param array $params
     *
     * @return void
     */
    public function hookActionOrderSlipAdd(array $params)
    {
        if (!Settings::getBool(Settings::REFUND_ON_STATE) || empty($params['order'])) {
            return;
        }

        $order = $params['order'];

        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return;
        }

        $amount = 0.0;
        foreach (isset($params['productList']) ? $params['productList'] : [] as $product) {
            $amount += isset($product['amount']) ? (float) $product['amount'] : 0.0;
        }

        if (!empty($params['qtyList']) && $amount <= 0) {
            return;
        }

        if ($amount <= 0) {
            return;
        }

        $minorUnits = Money::toMinorUnits($amount);
        $manager = $this->getBriqpayServices()->getOrderManager();

        try {
            // Tell Briqpay which products came back, not just how much.
            //
            // The slip already says so -- PrestaShop's refund screen asked the
            // merchant product by product -- and a PSP that settles per line
            // needs that to keep the buyer's invoice right. It is only worth
            // sending if it is worth exactly what the slip refunds, though:
            // shipping refunded on the slip never reaches this hook, so a
            // mapping that does not reconcile would return a different sum than
            // the paperwork. The amount is always correct, so that is the
            // fallback.
            $lines = $this->mapRefundLines($order, isset($params['productList']) ? $params['productList'] : []);

            if ($lines !== null && RefundLineMapper::matchesAmount($lines, $minorUnits)) {
                $manager->refundLines($order, $lines, $minorUnits);
            } else {
                $manager->refund($order, $minorUnits);
            }

            $this->logOrderMessage($order, sprintf('Briqpay: partial refund of %.2f processed.', $amount));
        } catch (ApiException $e) {
            Logger::error('Partial refund failed.', [
                'orderId' => (int) $order->id,
                'error' => $e->getMerchantMessage(),
            ]);

            $this->logOrderMessage($order, 'Briqpay refund error: ' . $e->getMerchantMessage());
        }
    }

    /**
     * The Briqpay lines a credit slip refunds, if they can be resolved.
     *
     * @param \Order $order
     * @param array  $productList
     *
     * @return array|null
     */
    private function mapRefundLines(Order $order, array $productList)
    {
        $record = $this->getBriqpayServices()->getOrderRepository()->findByOrderId((int) $order->id);

        if ($record === null || empty($record['session_id'])) {
            return null;
        }

        try {
            $session = $this->getBriqpayServices()->getSessionManager()->read((string) $record['session_id']);
        } catch (ApiException $e) {
            return null;
        }

        $cart = isset($session['data']['order']['cart']) && is_array($session['data']['order']['cart'])
            ? $session['data']['order']['cart']
            : [];

        return (new RefundLineMapper())->map($order, $productList, $cart);
    }

    /**
     * @param int $stateId
     *
     * @return bool
     */
    private function isCaptureState($stateId)
    {
        $captureStates = [
            (int) Configuration::get('PS_OS_SHIPPED'),
            (int) Configuration::get('PS_OS_DELIVERED'),
        ];

        return in_array((int) $stateId, array_filter($captureStates), true);
    }

    /**
     * Leave a trace on the order so merchants can see what the module did.
     *
     * @param Order  $order
     * @param string $message
     *
     * @return void
     */
    private function logOrderMessage(Order $order, $message)
    {
        try {
            $customerMessage = new Message();
            $customerMessage->id_order = (int) $order->id;
            $customerMessage->message = Tools::substr($message, 0, 1600);
            $customerMessage->private = true;
            $customerMessage->add();
        } catch (Exception $e) {
            Logger::error('Could not attach a message to the order.', ['error' => $e->getMessage()]);
        }
    }

    /* ---------------------------------------------------------------------
     * Back office: order panel
     * ------------------------------------------------------------------ */

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderMainBottom(array $params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        $orderId = (int) $params['id_order'];
        $order = new Order($orderId);

        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        // Read the live state rather than the stored snapshot: captures and
        // refunds otherwise reach this page only as fast as the webhooks do, and
        // a webhook that never arrives leaves it permanently wrong with nothing
        // the merchant can press to fix it.
        $record = $this->getBriqpayServices()->getPaymentSync()->refresh($orderId);
        $hppEnabled = Settings::getBool(Settings::HPP_ENABLED);

        // Not stored: true only for as long as the read that produced them.
        $pendingCapture = isset($record['pending_capture']) ? (int) $record['pending_capture'] : 0;
        $pendingRefund = isset($record['pending_refund']) ? (int) $record['pending_refund'] : 0;

        // With hosted payment pages on, the panel is also the place a merchant
        // generates a link for an order taken by phone, so it has to render
        // before any Briqpay payment exists.
        if ($record === null && !$hppEnabled) {
            return '';
        }

        if ($record === null) {
            $record = OrderRepository::emptyRecord($orderId, (int) $order->id_cart);
        }

        $total = Money::toMinorUnits($order->total_paid_tax_incl);
        $captured = (int) $record['captured_amount'];
        $refunded = (int) $record['refunded_amount'];
        $currency = new Currency((int) $order->id_currency);

        $this->context->smarty->assign([
            'briqpay' => $record,
            'briqpayDashboardLink' => OrderRepository::buildDashboardLink(
                $record['session_id'],
                $record['merchant_id']
            ),
            'briqpayOrderId' => $orderId,
            'briqpayCurrency' => $currency->iso_code,
            'briqpayTotals' => [
                'total' => $this->formatAmount($total, $currency),
                'captured' => $this->formatAmount($captured, $currency),
                'refunded' => $this->formatAmount($refunded, $currency),
                'capturable' => $this->formatAmount(OrderManager::getCapturableAmount($record, $order), $currency),
                'refundable' => $this->formatAmount(OrderManager::getRefundableAmount($record), $currency),
            ],
            'briqpayCapturableRaw' => OrderManager::getCapturableAmount($record, $order) / 100,
            'briqpayRefundableRaw' => OrderManager::getRefundableAmount($record) / 100,
            // A payment Briqpay settles itself must not offer a Capture
            // button: pressing it either fails or double-charges, and until the
            // capture webhook lands the panel would otherwise claim nothing has
            // been captured on a payment that is already settled.
            'briqpayAutoCaptured' => (bool) $record['auto_captured'],
            // Capture and cancel act on an authorisation, so they wait until
            // there is one. While the payment is pending the shopper may still
            // be finishing a 3-D Secure step and Briqpay can still reject it;
            // offering the buttons then invites an operation that fails, or
            // races the approval.
            'briqpayCanCapture' => OrderManager::allowsAuthorisationOperations($record)
                && !$record['auto_captured']
                && $pendingCapture === 0
                && OrderManager::getCapturableAmount($record, $order) > 0,
            'briqpayCanRefund' => $pendingRefund === 0
                && OrderManager::getRefundableAmount($record) > 0,
            // A capture Briqpay has accepted but not settled counts for nothing
            // in captured_amount, so without this the panel reports an order
            // with nothing captured and offers the button again.
            'briqpayCapturePending' => $pendingCapture > 0,
            'briqpayRefundPending' => $pendingRefund > 0,
            'briqpayPendingLabel' => $this->formatAmount(
                $pendingCapture > 0 ? $pendingCapture : $pendingRefund,
                $currency
            ),
            // Cancel releases the authorisation a pending capture draws on, so
            // it waits for that capture too.
            'briqpayCanCancel' => OrderManager::allowsAuthorisationOperations($record)
                && !$record['auto_captured']
                && $pendingCapture === 0
                && $captured === 0,
            'briqpayAwaitingCapture' => (bool) $record['auto_captured'] && $captured === 0,
            'briqpayAwaitingApproval' => !OrderManager::allowsAuthorisationOperations($record)
                && StatusMapper::isPending((string) $record['status']),
            // Rejected and cancelled payments offer nothing either, but saying
            // they are "fully settled" would be the opposite of the truth.
            'briqpayPaymentClosed' => !OrderManager::allowsAuthorisationOperations($record)
                && !StatusMapper::isPending((string) $record['status']),
            'briqpayStatusLabel' => StatusMapper::normalise((string) $record['status']),
            'briqpayActionUrl' => $this->context->link->getAdminLink('AdminModules', true) .
                '&configure=' . $this->name . '&briqpay_action=1',
            'briqpayLogoUri' => $this->getPathUri() . 'logo.png',
            'briqpayIsLive' => Settings::isLiveMode(),
            'briqpayFlash' => $this->consumeFlash(),
            // The order page is rendered by Symfony in PrestaShop 8, where the
            // legacy controller's addCSS()/addJS() are silently ignored -- the
            // panel comes out completely unstyled. The template pulls its own
            // assets in instead, which works on both admin stacks.
            'briqpayCssUri' => $this->getPathUri() . 'views/css/briqpay_admin.css?v=' . $this->version,
            'briqpayJsUri' => $this->getPathUri() . 'views/js/briqpay_admin.js?v=' . $this->version,
            'briqpayHasPayment' => $record['session_id'] !== '',
            'briqpayHppEnabled' => $hppEnabled,
            'briqpayHppFlows' => $this->getHppFlowChoices(),
            'briqpayHppDefaultFlow' => Flow::normalise(
                $record['hpp_flow'] !== '' ? $record['hpp_flow'] : null
            ),
            'briqpayHppBlockReason' => $hppEnabled
                ? $this->getBriqpayServices()->getHostedPageManager()->getBlockReason($order)
                : null,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/order_panel.tpl');
    }

    /**
     * @param int      $minorUnits
     * @param Currency $currency
     *
     * @return string
     */
    private function formatAmount($minorUnits, Currency $currency)
    {
        return Tools::displayPrice(((int) $minorUnits) / 100, $currency);
    }

    /**
     * Whether a shared secret is already stored.
     *
     * @return bool
     */
    private function hasStoredSecret()
    {
        return Settings::getString(Settings::SECRET) !== '';
    }

    /**
     * Hosted payment page flows, as id/label pairs for a select.
     *
     * @return array<int, array{id:string, name:string}>
     */
    private function getHppFlowChoices()
    {
        $domain = 'Modules.Briqpaypaymentmodule.Admin';

        return [
            [
                'id' => Flow::B2C,
                'name' => $this->trans('Consumer', [], $domain),
            ],
            [
                'id' => Flow::B2B_PAYMENT,
                'name' => $this->trans('Business - payment methods only', [], $domain),
            ],
            [
                'id' => Flow::B2B_CHECKOUT,
                'name' => $this->trans('Business - full checkout', [], $domain),
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     * Back office: configuration
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('briqpay_action')) {
            return $this->handleAdminAction();
        }

        if (Tools::isSubmit('submitBriqpayModule')) {
            $errors = $this->postProcess();

            $output .= empty($errors)
                ? $this->displayConfirmation($this->trans('Settings saved.', [], 'Admin.Notifications.Success'))
                : $this->displayError(implode('<br>', $errors));
        }

        if (Tools::isSubmit('submitBriqpayTestConnection')) {
            $output .= $this->renderConnectionTest();
        }

        $this->context->controller->addCSS($this->getPathUri() . 'views/css/briqpay_admin.css');
        $this->context->controller->addJS($this->getPathUri() . 'views/js/briqpay_admin.js');

        $this->context->smarty->assign([
            'briqpayModuleVersion' => $this->version,
            'briqpayIsLive' => Settings::isLiveMode(),
            'briqpayIsConfigured' => Settings::isConfigured(),
            'briqpayWebhookUrl' => Settings::toPublicUrl(
                $this->context->link->getModuleLink($this->name, 'webhook', [], true)
            ),
            'briqpayLogoUri' => $this->getPathUri() . 'logo.png',
            'briqpayDocsUrl' => 'https://briqpay.com/plugins/prestashop',
            'briqpayMissingCurrencies' => $this->getCurrenciesNotEnabled(),
            'briqpaySupportEmail' => 'hello@briqpay.com',
            'briqpaySecretPreview' => Settings::getSecretPreview(),
        ]);

        $header = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output . $header . $this->renderForm();
    }

    /**
     * Capture / refund / cancel buttons on the order page post back here.
     *
     * The employee is returned to the order they came from rather than being
     * stranded on the module configuration screen, with the outcome carried
     * across in a one-shot cookie flash.
     *
     * @return string
     */
    private function handleAdminAction()
    {
        $orderId = (int) Tools::getValue('briqpay_order_id');
        $action = (string) Tools::getValue('briqpay_operation');
        $amount = (float) Tools::getValue('briqpay_amount');
        $order = new Order($orderId);

        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return $this->displayError($this->trans('Unknown order.', [], 'Modules.Briqpaypaymentmodule.Admin'));
        }

        $manager = $this->getBriqpayServices()->getOrderManager();
        $minorUnits = $amount > 0 ? Money::toMinorUnits($amount) : null;

        try {
            switch ($action) {
                case 'capture':
                    $manager->capture($order, $minorUnits);
                    $message = $this->trans('Payment captured.', [], 'Modules.Briqpaypaymentmodule.Admin');
                    break;
                case 'refund':
                    $manager->refund($order, $minorUnits);
                    $message = $this->trans('Payment refunded.', [], 'Modules.Briqpaypaymentmodule.Admin');
                    break;
                case 'cancel':
                    $manager->cancel($order);
                    $message = $this->trans('Authorisation cancelled.', [], 'Modules.Briqpaypaymentmodule.Admin');
                    break;
                case 'create_link':
                    $this->getBriqpayServices()->getHostedPageManager()->create(
                        $order,
                        (string) Tools::getValue('briqpay_hpp_flow')
                    );
                    $message = $this->trans('Payment link created.', [], 'Modules.Briqpaypaymentmodule.Admin');
                    break;
                default:
                    return $this->displayError($this->trans('Unknown operation.', [], 'Modules.Briqpaypaymentmodule.Admin'));
            }
        } catch (ApiException $e) {
            $this->setFlash('error', $e->getMerchantMessage());
            $this->redirectToOrder($orderId);

            return '';
        }

        $this->logOrderMessage($order, 'Briqpay: ' . $message);
        $this->setFlash('success', $message);
        $this->redirectToOrder($orderId);

        return '';
    }

    /**
     * @param string $type    Either 'success' or 'error'.
     * @param string $message
     *
     * @return void
     */
    private function setFlash($type, $message)
    {
        if (!isset($this->context->cookie)) {
            return;
        }

        $this->context->cookie->briqpay_flash_type = $type;
        $this->context->cookie->briqpay_flash_message = $message;
        $this->context->cookie->write();
    }

    /**
     * Read and clear the pending flash message.
     *
     * @return array{type:string, message:string}|null
     */
    private function consumeFlash()
    {
        if (!isset($this->context->cookie) || empty($this->context->cookie->briqpay_flash_message)) {
            return null;
        }

        $flash = [
            'type' => (string) $this->context->cookie->briqpay_flash_type,
            'message' => (string) $this->context->cookie->briqpay_flash_message,
        ];

        $this->context->cookie->__unset('briqpay_flash_type');
        $this->context->cookie->__unset('briqpay_flash_message');
        $this->context->cookie->write();

        return $flash;
    }

    /**
     * @param int $orderId
     *
     * @return void
     */
    private function redirectToOrder($orderId)
    {
        // Built by hand rather than with getAdminLink()'s parameter array,
        // whose signature differs across the supported PrestaShop range.
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminOrders')
            . '&id_order=' . (int) $orderId
            . '&vieworder'
        );
    }

    /**
     * @return string
     */
    private function renderConnectionTest()
    {
        if (!Settings::isConfigured()) {
            return $this->displayError($this->trans(
                'Enter your merchant ID and secret before testing the connection.',
                [],
                'Modules.Briqpaypaymentmodule.Admin'
            ));
        }

        try {
            // A deliberately non-existent session: authentication is what is
            // being tested, so a 404 is the success case and a 401 is not.
            $this->getBriqpayServices()->getClient()->get('/v3/session/connection-test');
        } catch (ApiException $e) {
            if (in_array($e->getStatusCode(), [401, 403], true)) {
                return $this->displayError($this->trans(
                    'Briqpay rejected these credentials. Check the merchant ID and secret for this environment.',
                    [],
                    'Modules.Briqpaypaymentmodule.Admin'
                ));
            }

            if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                return $this->displayConfirmation($this->trans(
                    'Connection to Briqpay succeeded.',
                    [],
                    'Modules.Briqpaypaymentmodule.Admin'
                ));
            }

            return $this->displayError($this->trans(
                'Could not reach Briqpay: %error%',
                ['%error%' => $e->getMessage()],
                'Modules.Briqpaypaymentmodule.Admin'
            ));
        }

        return $this->displayConfirmation($this->trans(
            'Connection to Briqpay succeeded.',
            [],
            'Modules.Briqpaypaymentmodule.Admin'
        ));
    }

    /**
     * @return string
     */
    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitBriqpayModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm($this->getConfigForm());
    }

    /**
     * @return array
     */
    protected function getConfigForm()
    {
        $domain = 'Modules.Briqpaypaymentmodule.Admin';

        return [
            $this->buildFieldset(
                $this->trans('API credentials', [], $domain),
                'icon-key',
                [
                    $this->switchField(
                        Settings::LIVE_MODE,
                        $this->trans('Live mode', [], $domain),
                        $this->trans('Off: requests go to the Briqpay playground. On: real money moves.', [], $domain)
                    ),
                    [
                        'type' => 'text',
                        'name' => Settings::MERCHANT_ID,
                        'label' => $this->trans('Merchant ID', [], $domain),
                        'desc' => $this->trans('Found under Developers in the Briqpay dashboard.', [], $domain),
                        'required' => true,
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'password',
                        'name' => Settings::SECRET,
                        'label' => $this->trans('Shared secret', [], $domain),
                        // HelperForm always renders a password input with an
                        // empty value, so a stored secret looks lost. Say so,
                        // and treat a blank submission as "leave it alone".
                        'desc' => $this->hasStoredSecret()
                            ? $this->trans(
                                'A secret is saved. Leave this blank to keep it, or enter a new one to replace it.',
                                [],
                                $domain
                            )
                            : $this->trans('Credentials differ between playground and production.', [], $domain),
                        'required' => !$this->hasStoredSecret(),
                        // Stops the browser offering the employee's own saved
                        // back-office password here; accepting that suggestion
                        // silently replaces the Briqpay secret on the next save.
                        'autocomplete' => false,
                        'class' => 'fixed-width-xxl',
                    ],
                ]
            ),
            $this->buildFieldset(
                $this->trans('Checkout', [], $domain),
                'icon-shopping-cart',
                [
                    [
                        'type' => 'text',
                        'name' => Settings::DISPLAY_NAME,
                        'label' => $this->trans('Payment method name', [], $domain),
                        'desc' => $this->trans('Shown to shoppers at checkout. Leave empty to use "Briqpay Payments".', [], $domain),
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'select',
                        'name' => Settings::CUSTOMER_TYPE,
                        'label' => $this->trans('Customer type', [], $domain),
                        'desc' => $this->trans('"Detect automatically" reads the company field on the billing address.', [], $domain),
                        'options' => [
                            'query' => [
                                ['id' => Settings::CUSTOMER_TYPE_AUTO, 'name' => $this->trans('Detect automatically', [], $domain)],
                                ['id' => Settings::CUSTOMER_TYPE_CONSUMER, 'name' => $this->trans('Always B2C (consumer)', [], $domain)],
                                ['id' => Settings::CUSTOMER_TYPE_BUSINESS, 'name' => $this->trans('Always B2B (business)', [], $domain)],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    $this->switchField(
                        Settings::TAKEOVER_PAYMENT_STEP,
                        $this->trans('Replace the payment method list', [], $domain),
                        $this->trans('Show the Briqpay iframe in place of the payment option radio buttons.', [], $domain)
                    ),
                    $this->switchField(
                        Settings::ENFORCE_TERMS,
                        $this->trans('Require terms acceptance', [], $domain),
                        $this->trans('Keep the iframe suspended until the shopper ticks the terms checkbox.', [], $domain)
                    ),
                    [
                        'type' => 'text',
                        'name' => Settings::TERMS_URL,
                        'label' => $this->trans('Terms URL', [], $domain),
                        'desc' => $this->trans('Leave empty to use the shop\'s own terms and conditions page.', [], $domain),
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'name' => Settings::WEBHOOK_BASE_URL,
                        'label' => $this->trans('Public callback URL', [], $domain),
                        'desc' => $this->trans('Leave empty unless Briqpay cannot reach this shop on its own address. Set it to the public base URL Briqpay should send webhooks to, for example behind a reverse proxy or a development tunnel. Only webhooks use it; the shopper still browses the normal shop address.', [], $domain),
                        'class' => 'fixed-width-xxl',
                    ],
                ]
            ),
            $this->buildFieldset(
                $this->trans('Order management', [], $domain),
                'icon-refresh',
                [
                    $this->switchField(
                        Settings::CAPTURE_ON_STATE,
                        $this->trans('Capture on shipment', [], $domain),
                        $this->trans('Capture the payment when an order moves to Shipped or Delivered.', [], $domain)
                    ),
                    $this->switchField(
                        Settings::REFUND_ON_STATE,
                        $this->trans('Refund on refund status', [], $domain),
                        $this->trans('Refund via Briqpay when an order is refunded or a credit slip is issued.', [], $domain)
                    ),
                    $this->switchField(
                        Settings::CANCEL_ON_STATE,
                        $this->trans('Cancel on cancellation', [], $domain),
                        $this->trans('Release the authorisation when an order is cancelled.', [], $domain)
                    ),
                    $this->switchField(
                        Settings::AUTO_CAPTURE_STATE,
                        $this->trans('Advance auto-captured orders', [], $domain),
                        $this->trans('Move orders to Shipped when the payment method captures automatically.', [], $domain)
                    ),
                ]
            ),
            $this->buildFieldset(
                $this->trans('Hosted payment pages', [], $domain),
                'icon-link',
                [
                    $this->switchField(
                        Settings::HPP_ENABLED,
                        $this->trans('Enable payment links', [], $domain),
                        $this->trans('Generate a Briqpay payment link from the order page, for orders taken by phone or email.', [], $domain)
                    ),
                    [
                        'type' => 'select',
                        'name' => Settings::HPP_DEFAULT_FLOW,
                        'label' => $this->trans('Default flow', [], $domain),
                        'desc' => $this->trans('Pre-selected on the order page. "Full checkout" also collects company details and addresses.', [], $domain),
                        'options' => [
                            'query' => $this->getHppFlowChoices(),
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'name' => Settings::HPP_PAGE_TITLE,
                        'label' => $this->trans('Page title', [], $domain),
                        'desc' => $this->trans('Shown at the top of the payment page. Between 3 and 256 characters; leave empty for the Briqpay default.', [], $domain),
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'name' => Settings::HPP_LOGO_URL,
                        'label' => $this->trans('Logo URL', [], $domain),
                        'desc' => $this->trans('Absolute https URL to a PNG, JPG or SVG file.', [], $domain),
                        'class' => 'fixed-width-xxl',
                    ],
                    $this->switchField(
                        Settings::HPP_SHOW_CART,
                        $this->trans('Show the cart', [], $domain),
                        $this->trans('Display the order lines on the payment page.', [], $domain)
                    ),
                ]
            ),
            $this->buildFieldset(
                $this->trans('Validation and diagnostics', [], $domain),
                'icon-shield',
                [
                    $this->switchField(
                        Settings::DECISION_AMOUNT_CHECK,
                        $this->trans('Verify totals before approving', [], $domain),
                        $this->trans('Reject a purchase whose Briqpay total no longer matches the cart.', [], $domain)
                    ),
                    $this->switchField(
                        Settings::DECISION_ADDRESS_CHECK,
                        $this->trans('Verify addresses before approving', [], $domain),
                        $this->trans('Also compare billing and shipping addresses. Leave off unless you need it.', [], $domain)
                    ),
                    $this->switchField(
                        Settings::LOGGING_ENABLED,
                        $this->trans('Enable logging', [], $domain),
                        $this->trans('Write Briqpay activity to Advanced Parameters > Logs.', [], $domain)
                    ),
                    [
                        'type' => 'select',
                        'name' => Settings::LOG_LEVEL,
                        'label' => $this->trans('Log level', [], $domain),
                        'desc' => $this->trans('Use Debug only while troubleshooting; it is verbose.', [], $domain),
                        'options' => [
                            'query' => [
                                ['id' => Logger::LEVEL_ERROR, 'name' => $this->trans('Errors only', [], $domain)],
                                ['id' => Logger::LEVEL_INFO, 'name' => $this->trans('Info', [], $domain)],
                                ['id' => Logger::LEVEL_DEBUG, 'name' => $this->trans('Debug', [], $domain)],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                ]
            ),
        ];
    }

    /**
     * @param string $title
     * @param string $icon
     * @param array  $inputs
     *
     * @return array
     */
    private function buildFieldset($title, $icon, array $inputs)
    {
        return [
            'form' => [
                'legend' => ['title' => $title, 'icon' => $icon],
                'input' => $inputs,
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $description
     *
     * @return array
     */
    private function switchField($name, $label, $description)
    {
        return [
            'type' => 'switch',
            'name' => $name,
            'label' => $label,
            'desc' => $description,
            'is_bool' => true,
            'values' => [
                ['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
            ],
        ];
    }

    /**
     * @return array
     */
    protected function getConfigFormValues()
    {
        $values = [];

        foreach (array_keys(Settings::defaults()) as $key) {
            $values[$key] = Settings::get($key);
        }

        return $values;
    }

    /**
     * @return string[] Validation errors, empty when the save succeeded.
     */
    protected function postProcess()
    {
        $domain = 'Modules.Briqpaypaymentmodule.Admin';
        $errors = [];

        $merchantId = trim((string) Tools::getValue(Settings::MERCHANT_ID));
        $termsUrl = trim((string) Tools::getValue(Settings::TERMS_URL));
        $webhookBaseUrl = rtrim(trim((string) Tools::getValue(Settings::WEBHOOK_BASE_URL)), '/');

        // The secret input always posts back empty unless the employee retyped
        // it, so an empty submission means "keep what is stored" rather than
        // "clear it". Without this, saving any other setting would wipe the
        // credentials -- or, with the required check below, refuse to save at
        // all until the secret was typed in again every single time.
        $submittedSecret = trim((string) Tools::getValue(Settings::SECRET));
        $secret = $submittedSecret !== '' ? $submittedSecret : Settings::getString(Settings::SECRET);

        if ($merchantId === '') {
            $errors[] = $this->trans('The merchant ID is required.', [], $domain);
        }

        if ($secret === '') {
            $errors[] = $this->trans('The shared secret is required.', [], $domain);
        }

        if ($termsUrl !== '' && !Validate::isUrl($termsUrl)) {
            $errors[] = $this->trans('The terms URL is not a valid URL.', [], $domain);
        }

        // An unreachable callback host costs every webhook silently, so refuse
        // anything that is not an absolute http(s) URL rather than storing it.
        if ($webhookBaseUrl !== '' && !preg_match('#^https?://[^/\s]+#i', $webhookBaseUrl)) {
            $errors[] = $this->trans(
                'The public callback URL must be an absolute URL, for example https://example.com.',
                [],
                $domain
            );
        }

        $hppTitle = trim((string) Tools::getValue(Settings::HPP_PAGE_TITLE));
        if ($hppTitle !== '' && !HostedPageManager::isValidTitle($hppTitle)) {
            $errors[] = $this->trans(
                'The hosted page title must be between 3 and 256 characters.',
                [],
                $domain
            );
        }

        $hppLogo = trim((string) Tools::getValue(Settings::HPP_LOGO_URL));
        if ($hppLogo !== '' && !HostedPageManager::isValidLogoUrl($hppLogo)) {
            $errors[] = $this->trans(
                'The hosted page logo must be an absolute http(s) URL ending in .png, .jpg, .jpeg or .svg.',
                [],
                $domain
            );
        }

        if (!empty($errors)) {
            return $errors;
        }

        $booleans = [
            Settings::LIVE_MODE, Settings::TAKEOVER_PAYMENT_STEP, Settings::ENFORCE_TERMS,
            Settings::AUTO_CAPTURE_STATE, Settings::CAPTURE_ON_STATE, Settings::REFUND_ON_STATE,
            Settings::CANCEL_ON_STATE, Settings::DECISION_AMOUNT_CHECK, Settings::DECISION_ADDRESS_CHECK,
            Settings::LOGGING_ENABLED, Settings::HPP_ENABLED, Settings::HPP_SHOW_CART,
        ];

        foreach ($booleans as $key) {
            Configuration::updateValue($key, (int) (bool) Tools::getValue($key));
        }

        Configuration::updateValue(Settings::MERCHANT_ID, $merchantId);
        Configuration::updateValue(Settings::SECRET, $secret);
        Configuration::updateValue(Settings::TERMS_URL, $termsUrl);
        Configuration::updateValue(Settings::WEBHOOK_BASE_URL, $webhookBaseUrl);
        Configuration::updateValue(Settings::DISPLAY_NAME, trim((string) Tools::getValue(Settings::DISPLAY_NAME)));

        $customerType = (string) Tools::getValue(Settings::CUSTOMER_TYPE);
        $allowedTypes = [
            Settings::CUSTOMER_TYPE_AUTO,
            Settings::CUSTOMER_TYPE_CONSUMER,
            Settings::CUSTOMER_TYPE_BUSINESS,
        ];
        Configuration::updateValue(
            Settings::CUSTOMER_TYPE,
            in_array($customerType, $allowedTypes, true) ? $customerType : Settings::CUSTOMER_TYPE_AUTO
        );

        $logLevel = (string) Tools::getValue(Settings::LOG_LEVEL);
        $allowedLevels = [Logger::LEVEL_ERROR, Logger::LEVEL_INFO, Logger::LEVEL_DEBUG];
        Configuration::updateValue(
            Settings::LOG_LEVEL,
            in_array($logLevel, $allowedLevels, true) ? $logLevel : Logger::LEVEL_ERROR
        );

        Configuration::updateValue(Settings::HPP_PAGE_TITLE, $hppTitle);
        Configuration::updateValue(Settings::HPP_LOGO_URL, $hppLogo);
        Configuration::updateValue(
            Settings::HPP_DEFAULT_FLOW,
            Flow::normalise((string) Tools::getValue(Settings::HPP_DEFAULT_FLOW))
        );

        return [];
    }
}
