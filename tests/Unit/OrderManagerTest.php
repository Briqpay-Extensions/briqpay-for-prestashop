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

use Briqpay\Payment\Builder\OrderLineBuilder;
use Briqpay\Payment\Order\OrderCreator;
use Briqpay\Payment\Order\OrderManager;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Order\OrderManager
 * @covers \Briqpay\Payment\Builder\OrderLineBuilder
 * @covers \Briqpay\Payment\Order\OrderCreator
 * @covers \Briqpay\Payment\Session\SessionManager
 */
class OrderManagerTest extends TestCase
{
    public function testCapturableIsTheUncapturedRemainder(): void
    {
        $order = $this->makeOrder(1000.0);

        self::assertSame(100000, OrderManager::getCapturableAmount(['captured_amount' => 0], $order));
        self::assertSame(60000, OrderManager::getCapturableAmount(['captured_amount' => 40000], $order));
        self::assertSame(0, OrderManager::getCapturableAmount(['captured_amount' => 100000], $order));
    }

    public function testCapturableNeverGoesNegative(): void
    {
        $order = $this->makeOrder(100.0);

        self::assertSame(0, OrderManager::getCapturableAmount(['captured_amount' => 999999], $order));
    }

    /**
     * Only captured money can be refunded; an authorisation is cancelled, not
     * refunded.
     */
    public function testRefundableIsCapturedMinusAlreadyRefunded(): void
    {
        self::assertSame(0, OrderManager::getRefundableAmount(['captured_amount' => 0, 'refunded_amount' => 0]));
        self::assertSame(50000, OrderManager::getRefundableAmount(['captured_amount' => 50000, 'refunded_amount' => 0]));
        self::assertSame(20000, OrderManager::getRefundableAmount(['captured_amount' => 50000, 'refunded_amount' => 30000]));
        self::assertSame(0, OrderManager::getRefundableAmount(['captured_amount' => 50000, 'refunded_amount' => 50000]));
    }

    public function testRefundableNeverGoesNegative(): void
    {
        self::assertSame(0, OrderManager::getRefundableAmount(['captured_amount' => 100, 'refunded_amount' => 500]));
    }

    public function testOrderLinesAreBuiltFromTheOrderNotTheCart(): void
    {
        $order = $this->makeOrder(250.0, 200.0);
        $order->productsDetail = [[
            'product_id' => 5,
            'product_reference' => 'SKU-5',
            'product_name' => 'Widget',
            'product_quantity' => 2,
            'unit_price_tax_incl' => 125.0,
            'unit_price_tax_excl' => 100.0,
        ]];

        $lines = (new OrderLineBuilder())->buildLineItems($order);

        self::assertCount(1, $lines);
        self::assertSame('SKU-5', $lines[0]['reference']);
        self::assertSame(2, $lines[0]['quantity']);
        self::assertSame(10000, $lines[0]['unitPrice']);
        self::assertSame(2500, $lines[0]['taxRate']);
    }

    public function testOrderShippingAndDiscountBecomeTheirOwnLines(): void
    {
        $order = $this->makeOrder(300.0, 240.0);
        $order->total_shipping_tax_incl = 50.0;
        $order->total_shipping_tax_excl = 40.0;
        $order->total_discounts_tax_incl = 25.0;
        $order->total_discounts_tax_excl = 20.0;

        $types = array_column((new OrderLineBuilder())->buildLineItems($order), 'productType');

        self::assertContains('shipping_fee', $types);
        self::assertContains('discount', $types);
    }

    public function testPartialPayloadSplitsGrossIntoNetAndVat(): void
    {
        $order = $this->makeOrder(1250.0, 1000.0);

        $payload = (new OrderLineBuilder())->buildPartial($order, 12500, 'Partial refund');

        self::assertSame(12500, $payload['amountIncVat']);
        self::assertSame(10000, $payload['amountExVat']);
        self::assertSame(2500, $payload['cart'][0]['taxRate']);
        self::assertSame('Partial refund', $payload['cart'][0]['name']);
    }

    public function testPartialPayloadHandlesAZeroRatedOrder(): void
    {
        $order = $this->makeOrder(1000.0, 1000.0);

        $payload = (new OrderLineBuilder())->buildPartial($order, 5000);

        self::assertSame(5000, $payload['amountIncVat']);
        self::assertSame(5000, $payload['amountExVat']);
        self::assertSame(0, $payload['cart'][0]['taxRate']);
    }

    public function testTransactionExtractionTakesTheFirstEntry(): void
    {
        $session = ['data' => ['transactions' => [
            ['status' => 'approved', 'pspDisplayName' => 'Card'],
            ['status' => 'rejected'],
        ]]];

        self::assertSame('approved', OrderCreator::extractTransaction($session)['status']);
    }

    public function testTransactionExtractionToleratesAnEmptySession(): void
    {
        self::assertSame([], OrderCreator::extractTransaction([]));
        self::assertSame([], OrderCreator::extractTransaction(['data' => []]));
        self::assertSame([], OrderCreator::extractTransaction(['data' => ['transactions' => []]]));
    }

    public function testMerchantIdIsDecodedFromTheClientToken(): void
    {
        $payload = $this->base64UrlEncode((string) json_encode(['merchantId' => 'merch_123']));
        $token = 'header.' . $payload . '.signature';

        self::assertSame('merch_123', SessionManager::extractMerchantId(['clientToken' => $token]));
    }

    /**
     * JWTs use base64url, which swaps +/ for -_ and drops the padding. Plain
     * base64_decode mangles those, which is what the 1.x module used.
     */
    public function testMerchantIdDecodingHandlesBase64UrlAlphabetAndPadding(): void
    {
        $payload = $this->base64UrlEncode((string) json_encode(['merchantId' => 'a?b>c~d', 'pad' => 'x']));

        self::assertStringNotContainsString('=', $payload, 'base64url is unpadded');
        self::assertSame(
            'a?b>c~d',
            SessionManager::extractMerchantId(['clientToken' => 'h.' . $payload . '.s'])
        );
    }

    public function testMerchantIdIsEmptyForAMalformedToken(): void
    {
        self::assertSame('', SessionManager::extractMerchantId([]));
        self::assertSame('', SessionManager::extractMerchantId(['clientToken' => 'not-a-jwt']));
        self::assertSame('', SessionManager::extractMerchantId(['clientToken' => 'a.!!!notbase64!!!.c']));
    }

    private function makeOrder(float $incVat, ?float $exVat = null): \Order
    {
        $order = new \Order(1);
        $order->total_paid_tax_incl = $incVat;
        $order->total_paid_tax_excl = $exVat ?? $incVat;

        return $order;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
