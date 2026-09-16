<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Validation;

use Briqpay\Payment\Builder\AddressBuilder;
use Briqpay\Payment\Builder\CartBuilder;
use Briqpay\Payment\Config\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Server-side checks run during Briqpay's decision step.
 *
 * This is the last chance to refuse a purchase whose Briqpay session no longer
 * matches the shop's own view of the cart -- for instance if the buyer edited
 * the cart in another tab while the iframe was open.
 */
class SessionValidator
{
    /** Fields compared between a PrestaShop address and the Briqpay session. */
    public const COMPARED_ADDRESS_FIELDS = [
        'firstName', 'lastName', 'streetAddress', 'city', 'zip', 'country',
    ];

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
     * @param \Cart $cart
     * @param array $session
     *
     * @return ValidationResult
     */
    public function validate(\Cart $cart, array $session)
    {
        if (Settings::getBool(Settings::DECISION_AMOUNT_CHECK)) {
            $result = $this->validateAmounts($cart, $session);
            if (!$result->isValid()) {
                return $result;
            }
        }

        if (Settings::getBool(Settings::DECISION_ADDRESS_CHECK)) {
            $result = $this->validateAddresses($cart, $session);
            if (!$result->isValid()) {
                return $result;
            }
        }

        return ValidationResult::valid();
    }

    /**
     * @param \Cart $cart
     * @param array $session
     *
     * @return ValidationResult
     */
    public function validateAmounts(\Cart $cart, array $session)
    {
        if (!isset($session['data']['order'])) {
            return ValidationResult::invalid('The Briqpay session contains no order data.');
        }

        $order = $session['data']['order'];

        return self::compareAmounts(
            $this->cartBuilder->getAmountIncVat($cart),
            $this->cartBuilder->getAmountExVat($cart),
            isset($order['amountIncVat']) ? $order['amountIncVat'] : null,
            isset($order['amountExVat']) ? $order['amountExVat'] : null
        );
    }

    /**
     * Pure amount comparison, separated out so it can be exercised directly.
     *
     * @param int      $cartIncVat
     * @param int      $cartExVat
     * @param int|null $sessionIncVat
     * @param int|null $sessionExVat
     *
     * @return ValidationResult
     */
    public static function compareAmounts($cartIncVat, $cartExVat, $sessionIncVat, $sessionExVat)
    {
        if ($sessionIncVat === null || $sessionExVat === null) {
            return ValidationResult::invalid('The Briqpay session is missing order totals.');
        }

        if ((int) $cartIncVat !== (int) $sessionIncVat) {
            return ValidationResult::invalid(sprintf(
                'Cart total including VAT (%d) does not match the Briqpay session (%d).',
                (int) $cartIncVat,
                (int) $sessionIncVat
            ));
        }

        if ((int) $cartExVat !== (int) $sessionExVat) {
            return ValidationResult::invalid(sprintf(
                'Cart total excluding VAT (%d) does not match the Briqpay session (%d).',
                (int) $cartExVat,
                (int) $sessionExVat
            ));
        }

        return ValidationResult::valid();
    }

    /**
     * @param \Cart $cart
     * @param array $session
     *
     * @return ValidationResult
     */
    public function validateAddresses(\Cart $cart, array $session)
    {
        $pairs = [
            'billing' => $this->addressBuilder->buildBilling($cart),
            'shipping' => $this->addressBuilder->buildShipping($cart),
        ];

        foreach ($pairs as $type => $shopAddress) {
            if ($shopAddress === null || empty($session['data'][$type])) {
                continue;
            }

            $result = self::compareAddresses($shopAddress, $session['data'][$type], $type);
            if (!$result->isValid()) {
                return $result;
            }
        }

        return ValidationResult::valid();
    }

    /**
     * Compare the fields that matter, case- and whitespace-insensitively.
     *
     * Briqpay's hosted fields normalise casing and spacing, so a strict string
     * comparison (as the previous module used) rejected legitimate purchases.
     *
     * @param array  $shopAddress
     * @param array  $sessionAddress
     * @param string $type
     *
     * @return ValidationResult
     */
    public static function compareAddresses(array $shopAddress, array $sessionAddress, $type = 'billing')
    {
        foreach (self::COMPARED_ADDRESS_FIELDS as $field) {
            $expected = self::normaliseField(isset($shopAddress[$field]) ? $shopAddress[$field] : '');
            $actual = self::normaliseField(isset($sessionAddress[$field]) ? $sessionAddress[$field] : '');

            // An empty value on either side means the field was simply not
            // collected in that system; that is not a mismatch.
            if ($expected === '' || $actual === '') {
                continue;
            }

            if ($expected !== $actual) {
                return ValidationResult::invalid(sprintf(
                    'The %s %s does not match the Briqpay session.',
                    $type,
                    $field
                ));
            }
        }

        return ValidationResult::valid();
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function normaliseField($value)
    {
        $normalised = preg_replace('/\s+/u', ' ', trim((string) $value));

        return function_exists('mb_strtolower') ? mb_strtolower($normalised, 'UTF-8') : strtolower($normalised);
    }
}
