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
 * Builds Briqpay order payloads from a placed PrestaShop order.
 *
 * Captures and refunds must reflect what was actually purchased. The previous
 * module rebuilt them from the live cart, so a shopper who changed their cart
 * after checking out would have the wrong basket captured.
 */
class OrderLineBuilder
{
    /**
     * Full order payload, for a capture or refund of the whole order.
     *
     * @param \Order $order
     *
     * @return array
     */
    public function buildOrder(\Order $order)
    {
        $currency = new \Currency((int) $order->id_currency);

        return [
            'amountIncVat' => Money::toMinorUnits($order->total_paid_tax_incl),
            'amountExVat' => Money::toMinorUnits($order->total_paid_tax_excl),
            'currency' => (string) $currency->iso_code,
            'cart' => $this->buildLineItems($order),
        ];
    }

    /**
     * Payload for a partial amount, expressed as a single adjustment line.
     *
     * PrestaShop's partial refunds are entered as an amount rather than as a
     * set of lines, so this mirrors that shape.
     *
     * @param \Order $order
     * @param int    $amountIncVat Minor units.
     * @param string $label
     *
     * @return array
     */
    public function buildPartial(\Order $order, $amountIncVat, $label = 'Partial amount')
    {
        $currency = new \Currency((int) $order->id_currency);
        $taxRate = $this->getEffectiveTaxRate($order);
        $amountExVat = $taxRate > 0
            ? (int) round($amountIncVat / (1 + ($taxRate / 10000)))
            : (int) $amountIncVat;

        return [
            'amountIncVat' => (int) $amountIncVat,
            'amountExVat' => $amountExVat,
            'currency' => (string) $currency->iso_code,
            'cart' => [[
                'productType' => CartBuilder::TYPE_ADJUSTMENT,
                'reference' => 'partial-' . (int) $order->id,
                'name' => $label,
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => $amountExVat,
                'discountPercentage' => 0,
                'taxRate' => $taxRate,
            ]],
        ];
    }

    /**
     * @param \Order $order
     *
     * @return array<int, array>
     */
    public function buildLineItems(\Order $order)
    {
        $lines = [];

        foreach ($order->getProductsDetail() as $product) {
            $reference = isset($product['product_reference']) ? trim((string) $product['product_reference']) : '';
            if ($reference === '') {
                $reference = 'product-' . (int) $product['product_id'];
            }

            $lines[] = [
                'productType' => CartBuilder::TYPE_PHYSICAL,
                'reference' => $reference,
                'name' => (string) $product['product_name'],
                'quantity' => (int) $product['product_quantity'],
                'quantityUnit' => 'pc',
                'unitPrice' => Money::toMinorUnits($product['unit_price_tax_excl']),
                'discountPercentage' => 0,
                'taxRate' => Money::deriveTaxRate($product['unit_price_tax_incl'], $product['unit_price_tax_excl']),
            ];
        }

        $shippingIncVat = (float) $order->total_shipping_tax_incl;
        $shippingExVat = (float) $order->total_shipping_tax_excl;

        if (Money::toMinorUnits($shippingIncVat) !== 0) {
            $carrier = new \Carrier((int) $order->id_carrier);

            $lines[] = [
                'productType' => CartBuilder::TYPE_SHIPPING,
                'reference' => !empty($carrier->id_reference) ? (string) $carrier->id_reference : 'shipping',
                'name' => !empty($carrier->name) ? (string) $carrier->name : 'Shipping',
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => Money::toMinorUnits($shippingExVat),
                'discountPercentage' => 0,
                'taxRate' => Money::deriveTaxRate($shippingIncVat, $shippingExVat),
            ];
        }

        $wrappingIncVat = (float) $order->total_wrapping_tax_incl;
        $wrappingExVat = (float) $order->total_wrapping_tax_excl;

        if (Money::toMinorUnits($wrappingIncVat) !== 0) {
            $lines[] = [
                'productType' => CartBuilder::TYPE_FEE,
                'reference' => 'gift-wrapping',
                'name' => 'Gift wrapping',
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => Money::toMinorUnits($wrappingExVat),
                'discountPercentage' => 0,
                'taxRate' => Money::deriveTaxRate($wrappingIncVat, $wrappingExVat),
            ];
        }

        $discountIncVat = (float) $order->total_discounts_tax_incl;
        $discountExVat = (float) $order->total_discounts_tax_excl;

        if (Money::toMinorUnits($discountIncVat) !== 0) {
            $lines[] = [
                'productType' => CartBuilder::TYPE_DISCOUNT,
                'reference' => 'order-discount',
                'name' => 'Discount',
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => -Money::toMinorUnits($discountExVat),
                'discountPercentage' => 0,
                'taxRate' => Money::deriveTaxRate($discountIncVat, $discountExVat),
            ];
        }

        return $lines;
    }

    /**
     * Blended tax rate across the order, in basis points. Used to split a
     * partial amount into its net and VAT parts.
     *
     * @param \Order $order
     *
     * @return int
     */
    public function getEffectiveTaxRate(\Order $order)
    {
        return Money::deriveTaxRate($order->total_paid_tax_incl, $order->total_paid_tax_excl);
    }
}
