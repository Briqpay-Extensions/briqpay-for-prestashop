<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Hpp;

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Builder\OrderLineBuilder;
use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Support\Lock;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Creates Briqpay hosted payment pages for orders taken by phone or email.
 *
 * The merchant enters the order in the back office, generates a payment link
 * here, and sends it to the buyer. The order already exists, so unlike the
 * storefront flow nothing creates one on the way back -- the `order_status`
 * webhook simply moves the existing order to its paid state.
 */
class HostedPageManager
{
    public const SESSION_ENDPOINT = '/v3/session';
    public const HOSTED_PAGE_ENDPOINT = '/v3/hosted-page';

    /** Briqpay's own limits on the page configuration. */
    public const TITLE_MIN_LENGTH = 3;
    public const TITLE_MAX_LENGTH = 256;
    public const LOGO_MAX_LENGTH = 512;
    public const LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg'];

    /** @var Client */
    private $client;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var OrderLineBuilder */
    private $lineBuilder;

    public function __construct(
        Client $client,
        ?OrderRepository $orderRepository = null,
        ?OrderLineBuilder $lineBuilder = null
    ) {
        $this->client = $client;
        $this->orderRepository = $orderRepository !== null ? $orderRepository : new OrderRepository();
        $this->lineBuilder = $lineBuilder !== null ? $lineBuilder : new OrderLineBuilder();
    }

    /**
     * Create a hosted payment page for an order.
     *
     * @param \Order $order
     * @param string $flow
     *
     * @return array{url:string, id:string, flow:string}
     *
     * @throws ApiException
     */
    public function create(\Order $order, $flow)
    {
        $flow = Flow::normalise($flow);

        $lock = Lock::forOrder((int) $order->id);
        if (!$lock->acquire(10)) {
            throw new ApiException('Another payment link is already being created for this order.');
        }

        try {
            $blockReason = $this->getBlockReason($order);
            if ($blockReason !== null) {
                throw new ApiException($blockReason);
            }

            $sessionPayload = $this->buildSessionPayload($order, $flow);

            \Hook::exec('actionBriqpayHppSessionBefore', [
                'sessionData' => &$sessionPayload,
                'order' => $order,
                'flow' => $flow,
            ]);

            $session = $this->client->post(self::SESSION_ENDPOINT, $sessionPayload);

            if (empty($session['sessionId'])) {
                throw new ApiException('Briqpay did not return a session id for the hosted page.', 0, $session);
            }

            $config = $this->buildPageConfig();

            \Hook::exec('actionBriqpayHppConfigBefore', [
                'config' => &$config,
                'order' => $order,
                'flow' => $flow,
            ]);

            $hosted = $this->client->post(self::HOSTED_PAGE_ENDPOINT, [
                'sessionId' => $session['sessionId'],
                'config' => $config,
            ]);

            if (empty($hosted['url'])) {
                throw new ApiException('Briqpay did not return a hosted page URL.', 0, $hosted);
            }

            $result = [
                'url' => (string) $hosted['url'],
                'id' => isset($hosted['hostedPageId']) ? (string) $hosted['hostedPageId'] : '',
                'flow' => $flow,
            ];

            $this->persist($order, $session, $result);

            Logger::info('Created a hosted payment page.', [
                'orderId' => (int) $order->id,
                'sessionId' => $session['sessionId'],
                'flow' => $flow,
            ]);

            \Hook::exec('actionBriqpayHppCreated', [
                'order' => $order,
                'session' => $session,
                'hostedPage' => $result,
            ]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Why a payment link cannot be created for this order, or null when it can.
     *
     * @param \Order $order
     *
     * @return string|null
     */
    public function getBlockReason(\Order $order)
    {
        if (!Settings::getBool(Settings::HPP_ENABLED)) {
            return 'Hosted payment pages are disabled in the Briqpay settings.';
        }

        if (\Briqpay\Payment\Builder\Money::toMinorUnits($order->total_paid_tax_incl) <= 0) {
            return 'This order has no amount to pay.';
        }

        $record = $this->orderRepository->findByOrderId((int) $order->id);

        if ($record === null) {
            return null;
        }

        // Payment links exist for orders taken by phone or email, which reach
        // the back office with no payment of their own. An order that went
        // through the storefront already carries a Briqpay session, and issuing
        // a link against it opens a second one for the same order -- two
        // authorisations, and a buyer who can be charged twice.
        //
        // hpp_created separates the two: it is set only on a record this class
        // wrote, so regenerating a link that has expired stays possible while a
        // checkout session is off limits.
        if ($record['session_id'] !== '' && empty($record['hpp_created'])) {
            return 'This order was paid through the storefront checkout, so a payment link cannot be created for it.';
        }

        // Regenerating after the buyer has paid would hand out a link to a
        // second charge for the same order.
        if ((int) $record['captured_amount'] > 0) {
            return 'This order has already been captured, so a new payment link cannot be created.';
        }

        if (\Briqpay\Payment\Order\StatusMapper::isApproved((string) $record['status'])) {
            return 'This order has already been paid, so a new payment link cannot be created.';
        }

        return null;
    }

    /**
     * @param \Order $order
     *
     * @return bool
     */
    public function canCreate(\Order $order)
    {
        return $this->getBlockReason($order) === null;
    }

    /**
     * Session body for a hosted page.
     *
     * @param \Order $order
     * @param string $flow
     *
     * @return array
     */
    public function buildSessionPayload(\Order $order, $flow)
    {
        $context = \Context::getContext();
        $webhookUrl = Settings::toPublicUrl(
            $context->link->getModuleLink('briqpay_payment_module', 'webhook', [], true)
        );

        $payload = [
            'product' => [
                'type' => 'payment',
                'intent' => 'payment_one_time',
            ],
            'customerType' => Flow::customerType($flow),
            'locale' => $this->resolveLocale($order),
            'country' => $this->resolveCountry($order),
            'hooks' => [
                [
                    'eventType' => 'order_status',
                    'statuses' => \Briqpay\Payment\Builder\SessionPayloadBuilder::HOOK_ORDER_STATUSES,
                    'method' => 'POST',
                    'url' => $webhookUrl,
                ],
                [
                    'eventType' => 'capture_status',
                    'statuses' => \Briqpay\Payment\Builder\SessionPayloadBuilder::HOOK_CAPTURE_STATUSES,
                    'method' => 'POST',
                    'url' => $webhookUrl,
                ],
                [
                    'eventType' => 'refund_status',
                    'statuses' => \Briqpay\Payment\Builder\SessionPayloadBuilder::HOOK_REFUND_STATUSES,
                    'method' => 'POST',
                    'url' => $webhookUrl,
                ],
            ],
            'data' => [
                'order' => $this->lineBuilder->buildOrder($order),
            ],
            'references' => [
                'reference1' => (string) $order->reference,
                'reference2' => (string) $order->id,
                'cartId' => (string) $order->id_cart,
            ],
            'urls' => [
                'redirect' => $this->buildRedirectUrl($order),
                'terms' => Settings::getTermsUrl($context),
            ],
            'modules' => [
                'loadModules' => Flow::loadModules($flow),
            ],
        ];

        // When the hosted page collects the addresses itself, sending them
        // pre-filled would defeat the point of choosing that flow.
        if (!Flow::collectsAddresses($flow)) {
            $addresses = $this->buildAddresses($order, Flow::customerType($flow));

            foreach ($addresses as $key => $value) {
                $payload['data'][$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Page configuration, validated against Briqpay's limits.
     *
     * @return array
     */
    public function buildPageConfig()
    {
        $config = ['showCart' => Settings::getBool(Settings::HPP_SHOW_CART)];

        $title = trim(Settings::getString(Settings::HPP_PAGE_TITLE));
        if (self::isValidTitle($title)) {
            $config['pageTitle'] = $title;
        }

        $logo = trim(Settings::getString(Settings::HPP_LOGO_URL));
        if (self::isValidLogoUrl($logo)) {
            $config['logoUrl'] = $logo;
        }

        return $config;
    }

    /**
     * @param string $title
     *
     * @return bool
     */
    public static function isValidTitle($title)
    {
        $length = function_exists('mb_strlen') ? mb_strlen((string) $title, 'UTF-8') : strlen((string) $title);

        return $length >= self::TITLE_MIN_LENGTH && $length <= self::TITLE_MAX_LENGTH;
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    public static function isValidLogoUrl($url)
    {
        $url = (string) $url;

        if ($url === '' || strlen($url) > self::LOGO_MAX_LENGTH) {
            return false;
        }

        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::LOGO_EXTENSIONS, true);
    }

    /**
     * Store the link on the order so the merchant can resend it.
     *
     * @param \Order $order
     * @param array  $session
     * @param array  $hostedPage
     *
     * @return void
     */
    private function persist(\Order $order, array $session, array $hostedPage)
    {
        $currency = new \Currency((int) $order->id_currency);
        $existing = $this->orderRepository->findByOrderId((int) $order->id);

        $data = [
            'id_order' => (int) $order->id,
            'id_cart' => (int) $order->id_cart,
            'session_id' => (string) $session['sessionId'],
            'order_reference' => (string) $order->reference,
            'currency' => (string) $currency->iso_code,
            'customer_type' => Flow::customerType($hostedPage['flow']),
            'merchant_id' => SessionManager::extractMerchantId($session),
            'total_amount' => \Briqpay\Payment\Builder\Money::toMinorUnits($order->total_paid_tax_incl),
            'hpp_id' => $hostedPage['id'],
            'hpp_url' => $hostedPage['url'],
            'hpp_flow' => $hostedPage['flow'],
            'hpp_created' => date('Y-m-d H:i:s'),
        ];

        // A regenerated link supersedes the previous session; keep the captured
        // and refunded running totals rather than resetting them.
        if ($existing === null) {
            $data['status'] = '';
            $data['captured_amount'] = 0;
            $data['refunded_amount'] = 0;
            $data['auto_captured'] = 0;
        }

        $this->orderRepository->save($data);
    }

    /**
     * Where Briqpay sends the buyer once they have paid.
     *
     * @param \Order $order
     *
     * @return string
     */
    private function buildRedirectUrl(\Order $order)
    {
        $customer = new \Customer((int) $order->id_customer);

        return \Context::getContext()->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) \Module::getInstanceByName('briqpay_payment_module')->id,
            'id_order' => (int) $order->id,
            'key' => $customer->secure_key,
        ]);
    }

    /**
     * @param \Order $order
     * @param string $customerType
     *
     * @return array<string, array>
     */
    private function buildAddresses(\Order $order, $customerType)
    {
        $cart = new \Cart((int) $order->id_cart);

        if (!\Validate::isLoadedObject($cart)) {
            return [];
        }

        $builder = new \Briqpay\Payment\Builder\AddressBuilder();
        $data = [];

        $billing = $builder->buildBilling($cart);
        if ($billing !== null) {
            $data['billing'] = $billing;
        }

        $shipping = $builder->buildShipping($cart);
        if ($shipping !== null) {
            $data['shipping'] = $shipping;
        }

        // Only a business session may carry company data; on a consumer one
        // it stalls the Briqpay checkout. See SessionPayloadBuilder::buildData().
        if ($customerType === Settings::CUSTOMER_TYPE_BUSINESS) {
            $company = $builder->buildCompany($cart);
            if ($company !== null) {
                $data['company'] = $company;
            }
        }

        return $data;
    }

    /**
     * Use the buyer's own language, not whichever one the employee is using in
     * the back office.
     *
     * @param \Order $order
     *
     * @return string
     */
    private function resolveLocale(\Order $order)
    {
        return \Briqpay\Payment\Builder\Locale::fromLanguage(new \Language((int) $order->id_lang));
    }

    /**
     * @param \Order $order
     *
     * @return string
     */
    private function resolveCountry(\Order $order)
    {
        $addressId = (int) $order->id_address_invoice;

        if ($addressId > 0) {
            $address = new \Address($addressId);
            $iso = (string) \Country::getIsoById((int) $address->id_country);

            if ($iso !== '') {
                return $iso;
            }
        }

        return (string) \Context::getContext()->country->iso_code;
    }
}
