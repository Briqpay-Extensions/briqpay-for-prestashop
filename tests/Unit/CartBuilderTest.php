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

use Briqpay\Payment\Builder\CartBuilder;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Builder\CartBuilder
 */
class CartBuilderTest extends TestCase
{
    /** @var CartBuilder */
    private $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new CartBuilder();
    }

    public function testProductLinesCarryPriceQuantityAndTaxRate(): void
    {
        $cart = $this->makeCart(
            ['1:3' => 250.0, '0:3' => 200.0],
            [$this->makeProduct(['price' => 100.0, 'cart_quantity' => 2, 'rate' => 25.0])]
        );

        $lines = $this->builder->buildLineItems($cart);
        $product = $lines[0];

        self::assertSame(CartBuilder::TYPE_PHYSICAL, $product['productType']);
        self::assertSame('SKU-1', $product['reference']);
        self::assertSame(2, $product['quantity']);
        self::assertSame(10000, $product['unitPrice']);
        self::assertSame(2500, $product['taxRate']);
    }

    public function testVirtualProductsAreMarkedDigital(): void
    {
        $cart = $this->makeCart(
            ['1:3' => 125.0, '0:3' => 100.0],
            [$this->makeProduct(['is_virtual' => true])]
        );

        self::assertSame(CartBuilder::TYPE_DIGITAL, $this->builder->buildLineItems($cart)[0]['productType']);
    }

    /**
     * Briqpay requires a non-empty reference on every line, but PrestaShop
     * leaves it blank whenever the merchant never entered an SKU.
     */
    public function testProductsWithoutAnSkuGetASyntheticReference(): void
    {
        $cart = $this->makeCart(
            ['1:3' => 125.0, '0:3' => 100.0],
            [$this->makeProduct(['reference' => '', 'id_product' => 42, 'id_product_attribute' => 7])]
        );

        self::assertSame('product-42-7', $this->builder->buildLineItems($cart)[0]['reference']);
    }

    public function testShippingIsAddedAsItsOwnLine(): void
    {
        $cart = $this->makeCart([
            '1:3' => 175.0,
            '0:3' => 140.0,
            '1:5' => 50.0,
            '0:5' => 40.0,
        ], [$this->makeProduct()]);

        $shipping = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_SHIPPING);

        self::assertNotNull($shipping);
        self::assertSame(4000, $shipping['unitPrice']);
        self::assertSame(2500, $shipping['taxRate']);
        self::assertSame(1, $shipping['quantity']);
    }

    /**
     * Reproduced live on a real Finnish (25.5% VAT) order: a shipping fee of
     * 7.00 at 25.5% is 8.785 exactly, which Cart::getOrderTotal() rounds to
     * 8.79 before this ever sees it -- and deriving the rate from those two
     * already-rounded totals, (8.79 - 7.00) / 7.00, reads back 25.57%, a rate
     * that exists nowhere. Reading it from the carrier's own tax
     * configuration instead cannot drift the same way.
     */
    public function testShippingTaxRateIsReadFromTheCarrierNotDerivedFromRoundedTotals(): void
    {
        \Carrier::$taxRate = 25.5;

        // Exactly the amounts observed live: derived, these give 25.57%
        // (2557 basis points), not the true 25.5% (2550).
        $cart = $this->makeCart([
            '1:3' => 175.0,
            '0:3' => 140.0,
            '1:5' => 8.79,
            '0:5' => 7.00,
        ], [$this->makeProduct()]);

        $shipping = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_SHIPPING);

        self::assertNotNull($shipping);
        self::assertSame(2550, $shipping['taxRate'], 'should read the carrier real rate, not the rounding-distorted derivation');
    }

    /**
     * Without a usable delivery address, there is nothing to look the rate up
     * against, so this has to fall back to the old derivation rather than
     * fail the whole checkout.
     */
    public function testShippingTaxRateFallsBackToDerivationWithoutAnAddress(): void
    {
        \Carrier::$taxRate = 25.5; // must not be read: the fallback should ignore it

        $cart = $this->makeCart([
            '1:3' => 175.0,
            '0:3' => 140.0,
            '1:5' => 50.0,
            '0:5' => 40.0,
        ], [$this->makeProduct()]);
        $cart->id_address_delivery = 0;

        $shipping = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_SHIPPING);

        self::assertNotNull($shipping);
        self::assertSame(2500, $shipping['taxRate'], 'derived from 50.0/40.0, not the carrier stub value of 25.5');
    }

    public function testFreeShippingProducesNoShippingLine(): void
    {
        $cart = $this->makeCart([
            '1:3' => 125.0,
            '0:3' => 100.0,
            '1:5' => 0.0,
            '0:5' => 0.0,
        ], [$this->makeProduct()]);

        self::assertNull($this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_SHIPPING));
    }

    public function testDiscountsBecomeNegativeLines(): void
    {
        $cart = $this->makeCart(['1:3' => 100.0, '0:3' => 80.0], [$this->makeProduct()]);
        $cart->cartRules = [[
            'code' => 'SUMMER10',
            'description' => 'Summer sale',
            'value_real' => 25.0,
            'value_tax_exc' => 20.0,
        ]];

        $discount = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_DISCOUNT);

        self::assertNotNull($discount);
        self::assertSame('SUMMER10', $discount['reference']);
        self::assertSame(-2000, $discount['unitPrice']);
        self::assertSame(2500, $discount['taxRate']);
    }

    public function testDiscountWithoutACodeFallsBackToAPositionalReference(): void
    {
        $cart = $this->makeCart(['1:3' => 100.0, '0:3' => 80.0], [$this->makeProduct()]);
        $cart->cartRules = [[
            'code' => '',
            'description' => 'Manual rebate',
            'value_real' => 25.0,
            'value_tax_exc' => 20.0,
        ]];

        $discount = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_DISCOUNT);

        self::assertSame('discount-1', $discount['reference']);
        self::assertSame('Manual rebate', $discount['name']);
    }

    /**
     * Reported live on a real Finnish (25.5% VAT) order: a 10% voucher on a
     * cart entirely at 25.5% showed the discount line taxed at 25.49%.
     * value_real and value_tax_exc are, like a shipping fee, rounded to
     * currency precision before this ever reads them -- -6.70 exVat at 25.5%
     * is -8.4085 exactly, which rounds to -8.41, and deriving the rate from
     * those two rounded totals, (-8.41 - -6.70) / -6.70, reads back 25.49%,
     * not 25.5%.
     *
     * Unlike shipping there is no carrier to read the rate from directly, but
     * when every line the voucher discounts already shares one rate -- as
     * here, where product, product and shipping are all 25.5% -- that shared
     * rate unambiguously belongs to the discount too, and using it directly
     * cannot drift the way deriving it does.
     */
    public function testDiscountTaxRateUsesTheCartsSharedRateRatherThanDerivingItWhenUnambiguous(): void
    {
        \Carrier::$taxRate = 25.5; // must match the product rate, or the cart is not actually uniform

        $cart = $this->makeCart([
            '1:3' => 175.0,
            '0:3' => 140.0,
            '1:5' => 8.79,
            '0:5' => 7.00,
        ], [$this->makeProduct(['rate' => 25.5])]);
        // The exact figures observed live: derived, these give 25.49%
        // (2549 basis points), not the true, shared 25.5% (2550).
        $cart->cartRules = [[
            'code' => 'BRIQPAY10',
            'description' => 'Discount',
            'value_real' => -8.41,
            'value_tax_exc' => -6.70,
        ]];

        $discount = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_DISCOUNT);

        self::assertNotNull($discount);
        self::assertSame(
            2550,
            $discount['taxRate'],
            'should use the cart\'s one shared rate, not the rounding-distorted derivation'
        );
    }

    /**
     * A cart mixing VAT rates has no single rate that is unambiguously "the"
     * discount's own -- the reduction is spread across lines taxed
     * differently -- so derivation is the only sound approach there, and the
     * existing blended-rate behaviour must be unchanged.
     */
    public function testDiscountTaxRateStillDerivesWhenTheCartMixesRates(): void
    {
        $cart = $this->makeCart(
            ['1:3' => 200.0, '0:3' => 160.0],
            [
                $this->makeProduct(['id_product' => 1, 'rate' => 25.0]),
                $this->makeProduct(['id_product' => 2, 'rate' => 0.0]),
            ]
        );
        $cart->cartRules = [[
            'code' => 'MIX10',
            'description' => 'Discount',
            'value_real' => 25.0,
            'value_tax_exc' => 20.0,
        ]];

        $discount = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_DISCOUNT);

        self::assertNotNull($discount);
        self::assertSame(2500, $discount['taxRate'], 'derived from 25.0/20.0: two different product rates, no single shared one');
    }

    /**
     * A 100%-off cart rule has a zero net value. The 1.x module divided by it.
     */
    public function testFullyDiscountedRuleDoesNotBlowUp(): void
    {
        $cart = $this->makeCart(['1:3' => 0.0, '0:3' => 0.0], [$this->makeProduct()]);
        $cart->cartRules = [[
            'code' => 'FREE',
            'description' => 'Free',
            'value_real' => 0.0,
            'value_tax_exc' => 0.0,
        ]];

        $lines = $this->builder->buildLineItems($cart);

        // By reference, not by type: a rounding correction downwards is also a
        // discount line, so the type alone no longer identifies the voucher.
        $voucher = null;
        foreach ($lines as $line) {
            if ($line['reference'] === 'FREE') {
                $voucher = $line;
            }
        }

        self::assertNull($voucher, 'a rule worth nothing should add no line');
    }

    public function testGiftWrappingBecomesAFeeLine(): void
    {
        $cart = $this->makeCart([
            '1:3' => 150.0,
            '0:3' => 120.0,
            '1:6' => 25.0,
            '0:6' => 20.0,
        ], [$this->makeProduct()]);

        $wrapping = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_FEE);

        self::assertNotNull($wrapping);
        self::assertSame(2000, $wrapping['unitPrice']);
    }

    /**
     * The same rounding-amplification risk applies to gift wrapping, which
     * derives its rate the same way shipping used to.
     */
    public function testWrappingTaxRateIsReadDirectlyNotDerivedFromRoundedTotals(): void
    {
        \TaxManagerFactory::$wrappingTaxRate = 25.5;

        $cart = $this->makeCart([
            '1:3' => 175.0,
            '0:3' => 140.0,
            '1:6' => 8.79,
            '0:6' => 7.00,
        ], [$this->makeProduct()]);

        $wrapping = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_FEE);

        self::assertNotNull($wrapping);
        self::assertSame(2550, $wrapping['taxRate']);
    }

    public function testLinesMatchingTheTotalsProduceNoAdjustment(): void
    {
        $cart = $this->makeCart(
            ['1:3' => 125.0, '0:3' => 100.0],
            [$this->makeProduct(['price' => 100.0, 'cart_quantity' => 1, 'rate' => 25.0])]
        );

        self::assertNull($this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_ADJUSTMENT));
    }

    /**
     * PrestaShop rounds per item or per line depending on PS_ROUND_TYPE, so the
     * sum of the lines can legitimately differ from the cart total by a minor
     * unit or two. Briqpay refuses a session where they disagree, so the
     * builder emits a correcting line.
     */
    public function testRoundingDriftIsAbsorbedByAnAdjustmentLine(): void
    {
        $cart = $this->makeCart(
            ['1:3' => 125.02, '0:3' => 100.01],
            [$this->makeProduct(['price' => 100.0, 'cart_quantity' => 1, 'rate' => 25.0])]
        );

        // Upwards, so a surcharge. Never productType `adjustment`: Briqpay
        // accepts that one only on refunds, and a session carrying it is
        // rejected outright -- taking the whole checkout down, not just the
        // rounding.
        $adjustment = $this->findLine($this->builder->buildLineItems($cart), CartBuilder::TYPE_SURCHARGE);

        self::assertNotNull($adjustment);
        self::assertSame(1, $adjustment['unitPrice'], 'net drift of one minor unit');
        self::assertSame('rounding-adjustment', $adjustment['reference']);
    }

    /**
     * The rounding row must never use a product type a session refuses.
     *
     * Sending `adjustment` there fails with "cart.N no (or more than one)
     * schemas match", which reads as a malformed cart rather than as one line
     * being the wrong type -- and it broke checkout for every cart whose
     * rounding drifted.
     */
    public function testTheRoundingRowUsesATypeASessionAccepts(): void
    {
        foreach ([['1:3' => 125.02, '0:3' => 100.01], ['1:3' => 124.98, '0:3' => 99.99]] as $totals) {
            $cart = $this->makeCart(
                $totals,
                [$this->makeProduct(['price' => 100.0, 'cart_quantity' => 1, 'rate' => 25.0])]
            );

            foreach ($this->builder->buildLineItems($cart) as $line) {
                self::assertNotSame(
                    CartBuilder::TYPE_ADJUSTMENT,
                    $line['productType'],
                    'adjustment is accepted on refunds only'
                );
            }
        }
    }

    /**
     * A drift that only exists after VAT cannot be carried by a line at all: a
     * row worth zero before VAT is worth zero after it, whatever rate it is
     * given. Emitting one anyway just adds a row for Briqpay to reject.
     */
    public function testNoRowIsEmittedForADriftALineCannotCarry(): void
    {
        $lines = [[
            'productType' => CartBuilder::TYPE_PHYSICAL,
            'reference' => 'x',
            'quantity' => 1,
            'unitPrice' => 10000,
            'taxRate' => 2500,
        ]];

        self::assertNull($this->builder->buildRoundingAdjustment($lines, 12501, 10000));
    }

    public function testAdjustmentMakesTheLinesReconcileWithTheDeclaredTotals(): void
    {
        $cart = $this->makeCart(
            ['1:3' => 125.02, '0:3' => 100.01],
            [$this->makeProduct(['price' => 100.0, 'cart_quantity' => 1, 'rate' => 25.0])]
        );

        $lines = $this->builder->buildLineItems($cart);

        $netSum = 0;
        foreach ($lines as $line) {
            $netSum += $line['unitPrice'] * $line['quantity'];
        }

        self::assertSame($this->builder->getAmountExVat($cart), $netSum);
    }

    public function testBuildOrderCarriesTotalsAndCurrency(): void
    {
        $cart = $this->makeCart(['1:3' => 125.0, '0:3' => 100.0], [$this->makeProduct()]);

        $order = $this->builder->buildOrder($cart);

        self::assertSame(12500, $order['amountIncVat']);
        self::assertSame(10000, $order['amountExVat']);
        self::assertSame('SEK', $order['currency']);
        self::assertIsArray($order['cart']);
    }

    /**
     * @param array<int, array> $lines
     *
     * @return array|null
     */
    private function findLine(array $lines, string $type): ?array
    {
        foreach ($lines as $line) {
            if ($line['productType'] === $type) {
                return $line;
            }
        }

        return null;
    }
}
