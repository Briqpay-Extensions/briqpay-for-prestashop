<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Builder;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Conversions between PrestaShop's float amounts and Briqpay's integer units.
 *
 * Briqpay expresses money in minor units (cents/öre) and tax rates in basis
 * points, both as integers. Casting a float straight to int truncates, so
 * every conversion here rounds first -- 12.35 * 100 is 1234.9999... in IEEE
 *754 and would otherwise be sent as 1234.
 */
class Money
{
    /**
     * Convert a major-unit amount (12.35) to Briqpay minor units (1235).
     *
     * @param float|int|string $amount
     *
     * @return int
     */
    public static function toMinorUnits($amount)
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Convert a tax percentage (25.0) to Briqpay basis points (2500).
     *
     * @param float|int|string $percentage
     *
     * @return int
     */
    public static function percentageToBasisPoints($percentage)
    {
        return (int) round(((float) $percentage) * 100);
    }

    /**
     * Derive a tax rate in basis points from a gross/net pair.
     *
     * Returns 0 when the net amount is zero, which is what free shipping and
     * fully discounted lines look like -- the original module divided by zero
     * there.
     *
     * @param float|int $amountIncVat
     * @param float|int $amountExVat
     *
     * @return int
     */
    public static function deriveTaxRate($amountIncVat, $amountExVat)
    {
        $net = (float) $amountExVat;

        if (abs($net) < 0.00001) {
            return 0;
        }

        return self::percentageToBasisPoints(((((float) $amountIncVat) - $net) / $net) * 100);
    }
}
