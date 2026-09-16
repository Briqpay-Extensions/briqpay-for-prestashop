<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Briqpay\Payment\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Resets the stubbed PrestaShop globals between tests so state cannot leak.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::reset();
        \Db::reset();
        \Hook::reset();
        \PrestaShopLogger::reset();
        \Address::reset();
        \Customer::reset();
        \Context::reset();

        \Country::$isoCodes = [1 => 'SE', 2 => 'NO', 3 => 'DE'];
        \Carrier::$taxRate = 25.0;
        \TaxManagerFactory::$wrappingTaxRate = 25.0;
        \State::$names = [];
        \Currency::$registry = [1 => 'SEK', 2 => 'EUR'];
        \OrderState::$existing = [];

        // Order states the module resolves through Configuration.
        \Configuration::updateValue('PS_OS_PAYMENT', 2);
        \Configuration::updateValue('PS_OS_PREPARATION', 3);
        \Configuration::updateValue('PS_OS_SHIPPED', 4);
        \Configuration::updateValue('PS_OS_DELIVERED', 5);
        \Configuration::updateValue('PS_OS_CANCELED', 6);
        \Configuration::updateValue('PS_OS_REFUND', 7);
        \Configuration::updateValue('PS_OS_ERROR', 8);
    }

    /**
     * Build a cart whose totals and contents the test controls outright.
     *
     * @param array<string, float> $totals   Keyed "<withTax>:<type>".
     * @param array<int, array>    $products
     */
    protected function makeCart(array $totals = [], array $products = []): \Cart
    {
        $cart = new \Cart();
        $cart->totals = $totals;
        $cart->products = $products;

        return $cart;
    }

    /**
     * A product row shaped the way Cart::getProducts() returns it.
     *
     * @return array<string, mixed>
     */
    protected function makeProduct(array $overrides = []): array
    {
        return array_merge([
            'id_product' => 1,
            'id_product_attribute' => 0,
            'reference' => 'SKU-1',
            'name' => 'Test product',
            'cart_quantity' => 1,
            'price' => 100.0,
            'rate' => 25.0,
            'is_virtual' => false,
        ], $overrides);
    }

    /**
     * A transport that returns canned HTTP responses and records the requests.
     *
     * @param array<int, array{status:int, body:string}> $responses
     * @param array<int, array>                          $recorded
     */
    protected function makeTransport(array $responses, array &$recorded): callable
    {
        return function ($method, $url, $body, $headers) use (&$responses, &$recorded) {
            $recorded[] = [
                'method' => $method,
                'url' => $url,
                'body' => $body,
                'headers' => $headers,
            ];

            $next = array_shift($responses);

            return $next ?? ['status' => 200, 'body' => '{}', 'error' => null];
        };
    }
}
