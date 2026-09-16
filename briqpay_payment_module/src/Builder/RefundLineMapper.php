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
 * Turns a PrestaShop credit slip into the Briqpay lines it refunds.
 *
 * PrestaShop's partial refund screen already asks the merchant exactly what
 * Briqpay wants to know -- which products, and how many of each -- and hands it
 * to actionOrderSlipAdd. Summing that into a single figure and sending it as an
 * `adjustment` line is accepted, but it throws the detail away: a PSP that
 * settles per line, which is much of why a merchant picks Klarna or an invoice
 * provider, can only keep the buyer's invoice right if it is told which lines
 * came back. Told only an amount, it cannot.
 *
 * So the slip's lines are matched against the session's, by reference. What
 * cannot be matched is left for the caller to fall back on.
 */
class RefundLineMapper
{
    /**
     * Briqpay lines for the products a credit slip refunds.
     *
     * @param \Order $order       The order the slip belongs to.
     * @param array  $productList actionOrderSlipAdd's productList, keyed by
     *                            order detail id.
     * @param array  $sessionCart The session's `data.order.cart`.
     *
     * @return array|null Null when the slip cannot be expressed as whole
     *                    session lines, and the caller should send an amount.
     */
    public function map(\Order $order, array $productList, array $sessionCart)
    {
        if ($productList === [] || $sessionCart === []) {
            return null;
        }

        $byReference = self::indexByReference($sessionCart);
        $details = self::detailsById($order);
        $lines = [];

        foreach ($productList as $entry) {
            $detailId = isset($entry['id_order_detail']) ? (int) $entry['id_order_detail'] : 0;
            $quantity = isset($entry['quantity']) ? (int) $entry['quantity'] : 0;

            if ($quantity <= 0 || !isset($details[$detailId])) {
                return null;
            }

            $reference = self::referenceFor($details[$detailId]);

            if ($reference === '' || !isset($byReference[$reference])) {
                return null;
            }

            $line = $byReference[$reference];

            // More than the session knows about cannot be refunded against it.
            if ($quantity > (int) $line['quantity']) {
                return null;
            }

            $line['quantity'] = $quantity;
            $lines[] = $line;
        }

        // Non-empty by construction: an empty productList returned above, and
        // every entry either appends a line or abandons the mapping.
        return $lines;
    }

    /**
     * Whether a set of lines is worth exactly what the slip refunds.
     *
     * The reconciliation is the safety rule, not a nicety. The slip is what the
     * shop has told the customer it is returning, and a mapping that drifts
     * from it -- a refunded shipping charge the hook never sees, a line the
     * session prices differently -- would return a different sum than the
     * paperwork says. Falling back to the amount is always correct; refunding
     * the wrong figure is not.
     *
     * @param array $lines
     * @param int   $amountIncVat Minor units, as the slip records it.
     *
     * @return bool
     */
    public static function matchesAmount(array $lines, $amountIncVat)
    {
        $total = 0;

        foreach ($lines as $line) {
            $exVat = (int) $line['unitPrice'] * (int) $line['quantity'];
            $rate = isset($line['taxRate']) ? (int) $line['taxRate'] : 0;
            $total += (int) round($exVat * (1 + $rate / 10000));
        }

        return $total === (int) $amountIncVat;
    }

    /**
     * @param array $sessionCart
     *
     * @return array<string, array>
     */
    private static function indexByReference(array $sessionCart)
    {
        $indexed = [];

        foreach ($sessionCart as $line) {
            if (!empty($line['reference'])) {
                $indexed[(string) $line['reference']] = $line;
            }
        }

        return $indexed;
    }

    /**
     * @param \Order $order
     *
     * @return array<int, array>
     */
    private static function detailsById(\Order $order)
    {
        $byId = [];

        foreach ($order->getProductsDetail() as $detail) {
            $byId[(int) $detail['id_order_detail']] = $detail;
        }

        return $byId;
    }

    /**
     * The reference the session was opened with, for one order detail.
     *
     * Mirrors OrderLineBuilder: the two have to agree, or nothing matches.
     *
     * @param array $detail
     *
     * @return string
     */
    private static function referenceFor(array $detail)
    {
        $reference = isset($detail['product_reference']) ? trim((string) $detail['product_reference']) : '';

        if ($reference !== '') {
            return $reference;
        }

        return isset($detail['product_id']) ? 'product-' . (int) $detail['product_id'] : '';
    }
}
