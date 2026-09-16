<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Builder;

use Briqpay\Payment\Config\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Assembles the create-session and update-session request bodies.
 */
class SessionPayloadBuilder
{
    /** Briqpay webhook events the module subscribes to. */
    public const HOOK_ORDER_STATUSES = [
        'order_pending',
        'order_rejected',
        'order_cancelled',
        'order_approved_not_captured',
    ];
    public const HOOK_CAPTURE_STATUSES = ['approved', 'rejected'];
    public const HOOK_REFUND_STATUSES = ['approved', 'rejected'];

    /** @var CartBuilder */
    private $cartBuilder;

    /** @var AddressBuilder */
    private $addressBuilder;

    public function __construct(?CartBuilder $cartBuilder = null, ?AddressBuilder $addressBuilder = null)
    {
        $this->cartBuilder = $cartBuilder !== null ? $cartBuilder : new CartBuilder();
        $this->addressBuilder = $addressBuilder !== null ? $addressBuilder : new AddressBuilder();
    }

    /**
     * Body for POST /v3/session.
     *
     * @param \Context $context
     * @param string   $customerType
     *
     * @return array
     */
    public function buildCreatePayload(\Context $context, $customerType)
    {
        $cart = $context->cart;
        $webhookUrl = $this->getWebhookUrl($context);

        $payload = [
            'product' => [
                'type' => 'payment',
                'intent' => 'payment_one_time',
            ],
            'customerType' => $customerType,
            'locale' => $this->resolveLocale($context),
            'country' => $this->resolveCountry($context, $cart),
            'hooks' => [
                [
                    'eventType' => 'order_status',
                    'statuses' => self::HOOK_ORDER_STATUSES,
                    'method' => 'POST',
                    'url' => $webhookUrl,
                ],
                [
                    'eventType' => 'capture_status',
                    'statuses' => self::HOOK_CAPTURE_STATUSES,
                    'method' => 'POST',
                    'url' => $webhookUrl,
                ],
                [
                    'eventType' => 'refund_status',
                    'statuses' => self::HOOK_REFUND_STATUSES,
                    'method' => 'POST',
                    'url' => $webhookUrl,
                ],
            ],
            'data' => $this->buildData($cart, $customerType),
            'references' => [
                'cartId' => (string) $cart->id,
            ],
            'urls' => [
                'redirect' => $this->getRedirectUrl($context),
                'terms' => Settings::getTermsUrl($context),
            ],
            'modules' => [
                'loadModules' => ['payment'],
                'config' => [
                    'payment' => [
                        // Ask Briqpay to pause before taking any money so the
                        // shop gets to allow or reject the purchase. Without
                        // this the decision step is absent from the session,
                        // make_decision never fires, and every server-side
                        // check in the decision controller is dead code.
                        //
                        // The nesting matters: modules.payment.* is rejected
                        // with "body.modules has additional properties".
                        'decision' => ['enabled' => self::needsDecisionStep()],
                    ],
                ],
            ],
        ];

        return $payload;
    }

    /**
     * Body for PATCH /v3/session/{id} -- only the mutable parts of the session.
     *
     * @param \Context $context
     *
     * @return array
     */
    public function buildUpdatePayload(\Context $context)
    {
        // The session manager only reuses a session whose customer type still
        // matches, so resolving it again here cannot disagree with the session
        // being updated.
        return ['data' => $this->buildData(
            $context->cart,
            Settings::resolveCustomerType($context->cart)
        )];
    }

    /**
     * @param \Cart  $cart
     * @param string $customerType
     *
     * @return array
     */
    private function buildData(\Cart $cart, $customerType)
    {
        $data = ['order' => $this->cartBuilder->buildOrder($cart)];

        $billing = $this->addressBuilder->buildBilling($cart);
        if ($billing !== null) {
            $data['billing'] = $billing;
        }

        $shipping = $this->addressBuilder->buildShipping($cart);
        if ($shipping !== null) {
            $data['shipping'] = $shipping;
        }

        // Company data belongs to a business session only. Sending it alongside
        // customerType "consumer" stalls the Briqpay checkout on "Waiting for
        // address details" with no payment methods offered.
        if ($customerType === Settings::CUSTOMER_TYPE_BUSINESS) {
            $company = $this->addressBuilder->buildCompany($cart);
            if ($company !== null) {
                $data['company'] = $company;
            }
        }

        return $data;
    }

    /**
     * Whether any server-side validation is switched on.
     *
     * The decision step costs the shopper a round trip, so only ask for it when
     * there is actually something to check.
     *
     * @return bool
     */
    public static function needsDecisionStep()
    {
        return Settings::getBool(Settings::ENFORCE_TERMS)
            || Settings::getBool(Settings::DECISION_AMOUNT_CHECK)
            || Settings::getBool(Settings::DECISION_ADDRESS_CHECK);
    }

    /**
     * Stable fingerprint of the mutable session data.
     *
     * The module stores this alongside the session id and skips the PATCH when
     * nothing has actually changed, which keeps cart refreshes from generating
     * a Briqpay call per keystroke.
     *
     * @param array $data
     *
     * @return string
     */
    public function fingerprint(array $data)
    {
        $normalised = $this->normalise($data);

        return hash('sha256', (string) json_encode($normalised));
    }

    /**
     * Recursively sort keys so that a reordered but equivalent payload keeps
     * the same fingerprint.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function normalise($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->normalise($item);
        }

        if ($this->isAssociative($result)) {
            ksort($result);
        }

        return $result;
    }

    /**
     * @param array $array
     *
     * @return bool
     */
    private function isAssociative(array $array)
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param \Context $context
     *
     * @return string
     */
    private function getWebhookUrl(\Context $context)
    {
        return Settings::toPublicUrl(
            $context->link->getModuleLink('briqpay_payment_module', 'webhook', [], true)
        );
    }

    /**
     * Briqpay substitutes {briqpay_session} before redirecting the buyer back.
     *
     * @param \Context $context
     *
     * @return string
     */
    private function getRedirectUrl(\Context $context)
    {
        $url = $context->link->getModuleLink(
            'briqpay_payment_module',
            'validation',
            ['briqpay_session' => '__SESSION__'],
            true
        );

        return str_replace('__SESSION__', '{briqpay_session}', $url);
    }

    /**
     * @param \Context $context
     *
     * @return string
     */
    private function resolveLocale(\Context $context)
    {
        return Locale::fromLanguage($context->language);
    }

    /**
     * Prefer the buyer's own invoice country over the shop default.
     *
     * @param \Context $context
     * @param \Cart    $cart
     *
     * @return string
     */
    private function resolveCountry(\Context $context, \Cart $cart)
    {
        $addressId = (int) $cart->id_address_invoice;
        if ($addressId > 0) {
            $address = new \Address($addressId);
            $iso = (string) \Country::getIsoById((int) $address->id_country);
            if ($iso !== '') {
                return $iso;
            }
        }

        return (string) $context->country->iso_code;
    }
}
