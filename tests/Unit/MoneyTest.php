<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Briqpay\Payment\Tests\Unit;

use Briqpay\Payment\Builder\Money;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Builder\Money
 */
class MoneyTest extends TestCase
{
    /**
     * @dataProvider minorUnitProvider
     */
    public function testToMinorUnits(float $input, int $expected): void
    {
        self::assertSame($expected, Money::toMinorUnits($input));
    }

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public function minorUnitProvider(): array
    {
        return [
            'whole number' => [100.0, 10000],
            'two decimals' => [12.35, 1235],
            'zero' => [0.0, 0],
            'negative discount' => [-49.90, -4990],
            'large amount' => [199999.99, 19999999],
            // 1.005 is really 1.00499999999999998578..., strictly below the
            // true midpoint -- but PHP's round() applies its own precision
            // correction before comparing to the midpoint, and on every PHP
            // version this module supports (7.2-8.3) that correction treats
            // the gap as representation noise and rounds up: round(1.005 *
            // 100) is 101 on 7.4 through 8.3. Documented rather than "fixed":
            // PrestaShop's own totals are computed the same way on the same
            // PHP versions, so matching this is what keeps the line sums
            // reconciling. (PHP 8.4 reworked round()'s internal algorithm and
            // returns 100 for this input -- outside the range this module
            // targets. Re-verify this case if the supported range ever moves
            // past 8.3.)
            'binary midpoint rounds up on PHP <8.4' => [1.005, 101],
        ];
    }

    /**
     * The bug this guards: (int) (12.35 * 100) is 1234, because 12.35 has no
     * exact IEEE-754 representation. The 1.x module shipped that cast and
     * silently under-reported line prices by one minor unit.
     */
    public function testFloatArtefactsAreRoundedNotTruncated(): void
    {
        self::assertSame(1234, (int) (12.34 * 100), 'sanity: the naive cast still truncates');
        self::assertSame(1235, Money::toMinorUnits(12.35));
        self::assertSame(7030, Money::toMinorUnits(70.30));
        self::assertSame(2999, Money::toMinorUnits(29.99));
    }

    public function testPercentageToBasisPoints(): void
    {
        self::assertSame(2500, Money::percentageToBasisPoints(25.0));
        self::assertSame(1200, Money::percentageToBasisPoints(12.0));
        self::assertSame(600, Money::percentageToBasisPoints(6.0));
        self::assertSame(0, Money::percentageToBasisPoints(0));
    }

    public function testDeriveTaxRateFromGrossAndNet(): void
    {
        self::assertSame(2500, Money::deriveTaxRate(125.0, 100.0));
        self::assertSame(1200, Money::deriveTaxRate(112.0, 100.0));
        self::assertSame(0, Money::deriveTaxRate(100.0, 100.0));
    }

    /**
     * Free shipping and fully discounted lines have a zero net amount. The 1.x
     * module divided by it, producing a PHP warning and a NAN tax rate that
     * Briqpay rejected.
     */
    public function testDeriveTaxRateReturnsZeroInsteadOfDividingByZero(): void
    {
        self::assertSame(0, Money::deriveTaxRate(0.0, 0.0));
        self::assertSame(0, Money::deriveTaxRate(25.0, 0.0));
    }

    public function testDeriveTaxRateHandlesNegativeDiscountLines(): void
    {
        // A -100 net / -125 gross discount still carries 25% VAT.
        self::assertSame(2500, Money::deriveTaxRate(-125.0, -100.0));
    }
}
