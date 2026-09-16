<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Hpp;

use Briqpay\Payment\Config\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The hosted payment page flows Briqpay supports.
 *
 * A flow decides which customer type the session runs as and which Briqpay
 * modules the hosted page loads -- whether the buyer only picks a payment
 * method, or also looks up their company and fills in addresses.
 */
class Flow
{
    public const B2C = 'b2c';
    public const B2B_PAYMENT = 'b2b_payment_module';
    public const B2B_CHECKOUT = 'b2b_checkout';

    /**
     * @return array<string, array{customerType:string, loadModules:array<int,string>}>
     */
    public static function all()
    {
        return [
            self::B2C => [
                'customerType' => Settings::CUSTOMER_TYPE_CONSUMER,
                'loadModules' => ['payment'],
            ],
            self::B2B_PAYMENT => [
                'customerType' => Settings::CUSTOMER_TYPE_BUSINESS,
                'loadModules' => ['payment'],
            ],
            self::B2B_CHECKOUT => [
                'customerType' => Settings::CUSTOMER_TYPE_BUSINESS,
                'loadModules' => ['company_lookup', 'billing', 'shipping', 'payment'],
            ],
        ];
    }

    /**
     * @param string $flow
     *
     * @return bool
     */
    public static function exists($flow)
    {
        return array_key_exists((string) $flow, self::all());
    }

    /**
     * Fall back to the configured default -- and then to B2C -- rather than
     * letting an unknown flow reach the API.
     *
     * @param string|null $flow
     *
     * @return string
     */
    public static function normalise($flow)
    {
        if (self::exists($flow)) {
            return (string) $flow;
        }

        $configured = Settings::getString(Settings::HPP_DEFAULT_FLOW);

        return self::exists($configured) ? $configured : self::B2C;
    }

    /**
     * @param string $flow
     *
     * @return string
     */
    public static function customerType($flow)
    {
        $flows = self::all();
        $flow = self::normalise($flow);

        return $flows[$flow]['customerType'];
    }

    /**
     * @param string $flow
     *
     * @return array<int, string>
     */
    public static function loadModules($flow)
    {
        $flows = self::all();
        $flow = self::normalise($flow);

        return $flows[$flow]['loadModules'];
    }

    /**
     * Whether the hosted page collects addresses itself.
     *
     * When it does, the module must not pin the buyer's address into the
     * session -- doing so would hide the fields the merchant wanted shown.
     *
     * @param string $flow
     *
     * @return bool
     */
    public static function collectsAddresses($flow)
    {
        return in_array('billing', self::loadModules($flow), true);
    }
}
