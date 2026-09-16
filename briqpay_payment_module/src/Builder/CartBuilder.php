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
 * Turns a PrestaShop cart into the Briqpay `data.order` structure.
 */
class CartBuilder
{
    public const TYPE_PHYSICAL = 'physical';
    public const TYPE_DIGITAL = 'digital';
    public const TYPE_SHIPPING = 'shipping_fee';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_FEE = 'fee';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_SURCHARGE = 'surcharge';

    /**
     * Full order payload: totals, currency and line items.
     *
     * @param \Cart $cart
     *
     * @return array
     */
    public function buildOrder(\Cart $cart)
    {
        $currency = new \Currency((int) $cart->id_currency);

        return [
            'amountIncVat' => $this->getAmountIncVat($cart),
            'amountExVat' => $this->getAmountExVat($cart),
            'currency' => $currency->iso_code,
            'cart' => $this->buildLineItems($cart),
        ];
    }

    /**
     * @param \Cart $cart
     *
     * @return int
     */
    public function getAmountIncVat(\Cart $cart)
    {
        return Money::toMinorUnits($cart->getOrderTotal(true, \Cart::BOTH));
    }

    /**
     * @param \Cart $cart
     *
     * @return int
     */
    public function getAmountExVat(\Cart $cart)
    {
        return Money::toMinorUnits($cart->getOrderTotal(false, \Cart::BOTH));
    }

    /**
     * Build every Briqpay line item for the cart: products, shipping, wrapping,
     * discounts and -- when PrestaShop's own rounding disagrees with the sum of
     * the lines -- a correcting adjustment row.
     *
     * @param \Cart $cart
     *
     * @return array<int, array>
     */
    public function buildLineItems(\Cart $cart)
    {
        $chargeableLines = array_merge(
            $this->buildProductLines($cart),
            $this->buildShippingLines($cart),
            $this->buildWrappingLines($cart)
        );

        $lines = array_merge($chargeableLines, $this->buildDiscountLines($cart, $chargeableLines));

        $adjustment = $this->buildRoundingAdjustment(
            $lines,
            $this->getAmountIncVat($cart),
            $this->getAmountExVat($cart)
        );

        if ($adjustment !== null) {
            $lines[] = $adjustment;
        }

        return $lines;
    }

    /**
     * @param \Cart $cart
     *
     * @return array<int, array>
     */
    private function buildProductLines(\Cart $cart)
    {
        $lines = [];

        foreach ($cart->getProducts() as $product) {
            $reference = isset($product['reference']) ? trim((string) $product['reference']) : '';
            if ($reference === '') {
                // Briqpay requires a non-empty reference; PrestaShop leaves it
                // blank whenever the merchant never filled in an SKU.
                $reference = 'product-' . (int) $product['id_product'];
                if (!empty($product['id_product_attribute'])) {
                    $reference .= '-' . (int) $product['id_product_attribute'];
                }
            }

            $lines[] = [
                'productType' => !empty($product['is_virtual']) ? self::TYPE_DIGITAL : self::TYPE_PHYSICAL,
                'reference' => $reference,
                'name' => (string) $product['name'],
                'quantity' => (int) $product['cart_quantity'],
                'quantityUnit' => 'pc',
                'unitPrice' => Money::toMinorUnits($product['price']),
                'discountPercentage' => 0,
                'taxRate' => Money::percentageToBasisPoints(isset($product['rate']) ? $product['rate'] : 0),
            ];
        }

        return $lines;
    }

    /**
     * @param \Cart $cart
     *
     * @return array<int, array>
     */
    private function buildShippingLines(\Cart $cart)
    {
        $incVat = (float) $cart->getOrderTotal(true, \Cart::ONLY_SHIPPING);
        $exVat = (float) $cart->getOrderTotal(false, \Cart::ONLY_SHIPPING);

        if (Money::toMinorUnits($incVat) === 0) {
            return [];
        }

        $carrier = new \Carrier((int) $cart->id_carrier);
        $reference = !empty($carrier->id_reference) ? (string) $carrier->id_reference : 'shipping';

        return [[
            'productType' => self::TYPE_SHIPPING,
            'reference' => $reference,
            'name' => !empty($carrier->name) ? (string) $carrier->name : 'Shipping',
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => Money::toMinorUnits($exVat),
            'discountPercentage' => 0,
            'taxRate' => $this->shippingTaxRate($cart, $carrier, $incVat, $exVat),
        ]];
    }

    /**
     * The shipping fee's tax rate, read from the carrier's own tax
     * configuration rather than derived from its two rounded totals.
     *
     * Deriving it as ((incVat - exVat) / exVat) -- the same approach used for a
     * product line, where PrestaShop hands back per-unit prices already exact
     * to the rate -- fails here because Cart::getOrderTotal() rounds the
     * shipping fee to currency precision (2 decimals) before this ever reads
     * it. A fee of 7.00 at 25.5% is 8.785 exactly, which rounds to 8.79; a
     * derivation from the two ALREADY-ROUNDED totals then reads back 25.57%,
     * not 25.5% -- a rate that exists nowhere, reached by amplifying a
     * half-cent rounding artefact through division. It surfaced on Finland's
     * 25.5% rate and a round shipping fee, but nothing about it is
     * Finland-specific: any fee landing on a rounding boundary is at risk,
     * under any rate.
     *
     * Carrier::getTaxesRate() is PrestaShop's own resolution of the same tax
     * rules the shipping total was computed from, read directly rather than
     * reconstructed from currency-rounded amounts, so it cannot drift the
     * same way.
     *
     * @param \Cart    $cart
     * @param \Carrier $carrier
     * @param float    $incVat  Only used if the carrier cannot be resolved.
     * @param float    $exVat
     *
     * @return int
     */
    private function shippingTaxRate(\Cart $cart, \Carrier $carrier, $incVat, $exVat)
    {
        $addressId = (int) $cart->id_address_delivery;

        if ($addressId <= 0) {
            return Money::deriveTaxRate($incVat, $exVat);
        }

        $address = new \Address($addressId);

        if (!\Validate::isLoadedObject($address)) {
            return Money::deriveTaxRate($incVat, $exVat);
        }

        return Money::percentageToBasisPoints($carrier->getTaxesRate($address));
    }

    /**
     * @param \Cart $cart
     *
     * @return array<int, array>
     */
    private function buildWrappingLines(\Cart $cart)
    {
        $incVat = (float) $cart->getOrderTotal(true, \Cart::ONLY_WRAPPING);
        $exVat = (float) $cart->getOrderTotal(false, \Cart::ONLY_WRAPPING);

        if (Money::toMinorUnits($incVat) === 0) {
            return [];
        }

        return [[
            'productType' => self::TYPE_FEE,
            'reference' => 'gift-wrapping',
            'name' => 'Gift wrapping',
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => Money::toMinorUnits($exVat),
            'discountPercentage' => 0,
            'taxRate' => $this->wrappingTaxRate($cart, $incVat, $exVat),
        ]];
    }

    /**
     * The wrapping fee's tax rate, read directly for the same reason as
     * shippingTaxRate() above: deriving it from two amounts Cart::getOrderTotal()
     * has already rounded to currency precision can amplify a rounding
     * artefact into a rate that does not exist.
     *
     * @param \Cart $cart
     * @param float $incVat Only used if the address cannot be resolved.
     * @param float $exVat
     *
     * @return int
     */
    private function wrappingTaxRate(\Cart $cart, $incVat, $exVat)
    {
        $addressId = (int) $cart->id_address_delivery;

        if ($addressId <= 0) {
            return Money::deriveTaxRate($incVat, $exVat);
        }

        $address = new \Address($addressId);

        if (!\Validate::isLoadedObject($address)) {
            return Money::deriveTaxRate($incVat, $exVat);
        }

        $taxRulesGroupId = (int) \Configuration::get('PS_GIFT_WRAPPING_TAX_RULES_GROUP');
        $taxManager = \TaxManagerFactory::getManager($address, $taxRulesGroupId);

        return Money::percentageToBasisPoints($taxManager->getTaxCalculator()->getTotalRate());
    }

    /**
     * Cart rules become negative line items.
     *
     * @param \Cart $cart
     *
     * @return array<int, array>
     */
    private function buildDiscountLines(\Cart $cart, array $chargeableLines = [])
    {
        $lines = [];
        $uniformRate = self::commonTaxRate($chargeableLines);

        foreach ($cart->getCartRules() as $index => $discount) {
            $incVat = isset($discount['value_real']) ? (float) $discount['value_real'] : 0.0;
            $exVat = isset($discount['value_tax_exc']) ? (float) $discount['value_tax_exc'] : 0.0;

            if (Money::toMinorUnits($incVat) === 0 && Money::toMinorUnits($exVat) === 0) {
                continue;
            }

            $code = isset($discount['code']) ? trim((string) $discount['code']) : '';
            $name = isset($discount['description']) && $discount['description'] !== ''
                ? (string) $discount['description']
                : 'Discount';

            $lines[] = [
                'productType' => self::TYPE_DISCOUNT,
                'reference' => $code !== '' ? $code : 'discount-' . ((int) $index + 1),
                'name' => $name,
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => -Money::toMinorUnits($exVat),
                'discountPercentage' => 0,
                'taxRate' => $uniformRate !== null ? $uniformRate : Money::deriveTaxRate($incVat, $exVat),
            ];
        }

        return $lines;
    }

    /**
     * The one tax rate every chargeable line shares, or null when they differ.
     *
     * A voucher's own value_real/value_tax_exc reduction is, like the
     * shipping fee, rounded to currency precision before this ever reads it --
     * so deriving its rate the same way (incVat - exVat) / exVat is open to
     * the identical rounding amplification: on a cart entirely at 25.5% VAT, a
     * 10% voucher of -8.41/-8.79 derives to 25.49%, not the true 25.5%.
     *
     * Unlike shipping, a discount has no carrier to read an authoritative rate
     * from. But when every line it discounts shares one rate, that shared rate
     * is unambiguously the discount's own rate too, and reading it directly is
     * exact where deriving it is not. A cart mixing VAT rates has no single
     * correct discount rate to read this way -- the reduction is spread across
     * lines taxed differently -- so derivation remains the only sound approach
     * there, and this returns null to say so.
     *
     * @param array<int, array> $chargeableLines
     *
     * @return int|null
     */
    private static function commonTaxRate(array $chargeableLines)
    {
        $rates = array_values(array_unique(array_map(static function ($line) {
            return (int) $line['taxRate'];
        }, $chargeableLines)));

        return count($rates) === 1 ? $rates[0] : null;
    }

    /**
     * Briqpay rejects a session whose line items do not add up to the declared
     * totals. PrestaShop rounds per-line or per-item depending on PS_ROUND_TYPE,
     * so a few minor units of drift is normal; emit a single correcting row for
     * it rather than letting the session be refused.
     *
     * Deliberately not productType `adjustment`: that one is accepted only on
     * refunds, and a session carrying it is rejected outright with
     * "cart.N no (or more than one) schemas match" -- which takes the whole
     * checkout down, not just the rounding. A correction upwards is a
     * `surcharge` and one downwards a `discount`, both of which a session
     * accepts.
     *
     * @param array<int, array> $lines
     * @param int               $expectedIncVat
     * @param int               $expectedExVat
     *
     * @return array|null
     */
    public function buildRoundingAdjustment(array $lines, $expectedIncVat, $expectedExVat)
    {
        $sumExVat = 0;
        $sumIncVat = 0;

        foreach ($lines as $line) {
            $lineExVat = (int) $line['unitPrice'] * (int) $line['quantity'];
            $sumExVat += $lineExVat;
            $sumIncVat += (int) round($lineExVat * (1 + ((int) $line['taxRate'] / 10000)));
        }

        $deltaExVat = (int) $expectedExVat - $sumExVat;
        $deltaIncVat = (int) $expectedIncVat - $sumIncVat;

        // Nothing a line can carry. A row worth zero excluding VAT is worth zero
        // including it too, whatever tax rate it is given, so it cannot correct
        // a VAT-only difference -- it just adds a meaningless row for Briqpay to
        // reject.
        if ($deltaExVat === 0) {
            return null;
        }

        return [
            'productType' => $deltaExVat > 0 ? self::TYPE_SURCHARGE : self::TYPE_DISCOUNT,
            'reference' => 'rounding-adjustment',
            'name' => 'Rounding adjustment',
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => $deltaExVat,
            'discountPercentage' => 0,
            'taxRate' => Money::deriveTaxRate($deltaIncVat, $deltaExVat),
        ];
    }
}
