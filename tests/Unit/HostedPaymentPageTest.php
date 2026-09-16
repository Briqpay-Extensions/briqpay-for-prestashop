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

use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Hpp\Flow;
use Briqpay\Payment\Hpp\HostedPageManager;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Hpp\Flow
 * @covers \Briqpay\Payment\Hpp\HostedPageManager
 */
class HostedPaymentPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::updateValue(Settings::HPP_ENABLED, 1);
        \Configuration::updateValue(Settings::MERCHANT_ID, 'merchant');
        \Configuration::updateValue(Settings::SECRET, 'secret');

        \Address::$registry[1] = [
            'id' => 1,
            'firstname' => 'Anna',
            'lastname' => 'Svensson',
            'address1' => 'Storgatan 1',
            'postcode' => '11122',
            'city' => 'Stockholm',
            'id_country' => 1,
        ];
        \Customer::$registry[1] = ['id' => 1, 'email' => 'anna@example.com'];
    }

    /* ------------------------------------------------------------------ flows */

    public function testEachFlowMapsToACustomerTypeAndModuleSet(): void
    {
        self::assertSame('consumer', Flow::customerType(Flow::B2C));
        self::assertSame(['payment'], Flow::loadModules(Flow::B2C));

        self::assertSame('business', Flow::customerType(Flow::B2B_PAYMENT));
        self::assertSame(['payment'], Flow::loadModules(Flow::B2B_PAYMENT));

        self::assertSame('business', Flow::customerType(Flow::B2B_CHECKOUT));
        self::assertSame(
            ['company_lookup', 'billing', 'shipping', 'payment'],
            Flow::loadModules(Flow::B2B_CHECKOUT)
        );
    }

    public function testOnlyTheFullCheckoutFlowCollectsAddresses(): void
    {
        self::assertFalse(Flow::collectsAddresses(Flow::B2C));
        self::assertFalse(Flow::collectsAddresses(Flow::B2B_PAYMENT));
        self::assertTrue(Flow::collectsAddresses(Flow::B2B_CHECKOUT));
    }

    public function testAnUnknownFlowFallsBackToTheConfiguredDefault(): void
    {
        \Configuration::updateValue(Settings::HPP_DEFAULT_FLOW, Flow::B2B_CHECKOUT);

        self::assertSame(Flow::B2B_CHECKOUT, Flow::normalise('nonsense'));
        self::assertSame(Flow::B2B_CHECKOUT, Flow::normalise(null));
    }

    public function testAnUnknownDefaultFallsBackToB2c(): void
    {
        \Configuration::updateValue(Settings::HPP_DEFAULT_FLOW, 'also-nonsense');

        self::assertSame(Flow::B2C, Flow::normalise('nonsense'));
    }

    /* --------------------------------------------------------------- payload */

    public function testSessionPayloadUsesTheFlowsCustomerTypeAndModules(): void
    {
        $payload = $this->manager()->buildSessionPayload($this->makeOrder(), Flow::B2B_CHECKOUT);

        self::assertSame('business', $payload['customerType']);
        self::assertSame(
            ['company_lookup', 'billing', 'shipping', 'payment'],
            $payload['modules']['loadModules']
        );
    }

    public function testSessionPayloadCarriesTheOrderReferences(): void
    {
        $order = $this->makeOrder();
        $order->id = 42;
        $order->id_cart = 77;
        $order->reference = 'XYZABCDEF';

        $payload = $this->manager()->buildSessionPayload($order, Flow::B2C);

        self::assertSame('XYZABCDEF', $payload['references']['reference1']);
        self::assertSame('42', $payload['references']['reference2']);
        self::assertSame('77', $payload['references']['cartId']);
    }

    /**
     * The cartId reference is how the webhook finds its way back to the order,
     * since a hosted page has no browser session to fall back on.
     */
    public function testSessionPayloadAlwaysCarriesACartIdForWebhookResolution(): void
    {
        $payload = $this->manager()->buildSessionPayload($this->makeOrder(), Flow::B2B_CHECKOUT);

        self::assertArrayHasKey('cartId', $payload['references']);
        self::assertNotSame('', $payload['references']['cartId']);
    }

    public function testSessionPayloadSubscribesToAllThreeWebhookEvents(): void
    {
        $payload = $this->manager()->buildSessionPayload($this->makeOrder(), Flow::B2C);
        $events = array_column($payload['hooks'], 'eventType');

        self::assertContains('order_status', $events);
        self::assertContains('capture_status', $events);
        self::assertContains('refund_status', $events);
    }

    public function testPaymentOnlyFlowsPrefillTheBuyersAddress(): void
    {
        $payload = $this->manager()->buildSessionPayload($this->makeOrder(), Flow::B2C);

        self::assertArrayHasKey('billing', $payload['data']);
        self::assertSame('Storgatan 1', $payload['data']['billing']['streetAddress']);
    }

    /**
     * The full-checkout flow exists so the buyer can enter their own details;
     * pre-filling them would hide the fields the merchant asked to be shown.
     */
    public function testFullCheckoutFlowDoesNotPrefillAddresses(): void
    {
        $payload = $this->manager()->buildSessionPayload($this->makeOrder(), Flow::B2B_CHECKOUT);

        self::assertArrayNotHasKey('billing', $payload['data']);
        self::assertArrayNotHasKey('shipping', $payload['data']);
        self::assertArrayHasKey('order', $payload['data']);
    }

    public function testSessionPayloadUsesTheBuyersLanguageNotTheEmployees(): void
    {
        $order = $this->makeOrder();
        $order->id_lang = 1;

        $payload = $this->manager()->buildSessionPayload($order, Flow::B2C);

        self::assertSame('en-gb', $payload['locale']);
    }

    public function testSessionPayloadTotalsComeFromTheOrder(): void
    {
        $order = $this->makeOrder();
        $order->total_paid_tax_incl = 625.0;
        $order->total_paid_tax_excl = 500.0;

        $payload = $this->manager()->buildSessionPayload($order, Flow::B2C);

        self::assertSame(62500, $payload['data']['order']['amountIncVat']);
        self::assertSame(50000, $payload['data']['order']['amountExVat']);
    }

    /* ---------------------------------------------------------------- config */

    public function testPageConfigAlwaysDeclaresTheCartSetting(): void
    {
        \Configuration::updateValue(Settings::HPP_SHOW_CART, 0);

        self::assertSame(['showCart' => false], $this->manager()->buildPageConfig());
    }

    public function testPageConfigIncludesAValidTitleAndLogo(): void
    {
        \Configuration::updateValue(Settings::HPP_PAGE_TITLE, 'Pay your invoice');
        \Configuration::updateValue(Settings::HPP_LOGO_URL, 'https://cdn.example.com/logo.png');

        $config = $this->manager()->buildPageConfig();

        self::assertSame('Pay your invoice', $config['pageTitle']);
        self::assertSame('https://cdn.example.com/logo.png', $config['logoUrl']);
    }

    /**
     * Briqpay rejects the whole page request on an invalid title or logo, so
     * anything out of bounds is dropped rather than forwarded.
     */
    public function testInvalidTitleAndLogoAreOmittedRatherThanSent(): void
    {
        \Configuration::updateValue(Settings::HPP_PAGE_TITLE, 'ab');
        \Configuration::updateValue(Settings::HPP_LOGO_URL, 'not-a-url');

        $config = $this->manager()->buildPageConfig();

        self::assertArrayNotHasKey('pageTitle', $config);
        self::assertArrayNotHasKey('logoUrl', $config);
    }

    /**
     * @dataProvider titleProvider
     */
    public function testTitleValidation(string $title, bool $valid): void
    {
        self::assertSame($valid, HostedPageManager::isValidTitle($title));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function titleProvider(): array
    {
        return [
            'empty' => ['', false],
            'two characters' => ['ab', false],
            'three characters' => ['abc', true],
            'normal' => ['Pay your invoice', true],
            'at the limit' => [str_repeat('a', 256), true],
            'over the limit' => [str_repeat('a', 257), false],
        ];
    }

    /**
     * @dataProvider logoProvider
     */
    public function testLogoValidation(string $url, bool $valid): void
    {
        self::assertSame($valid, HostedPageManager::isValidLogoUrl($url));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function logoProvider(): array
    {
        return [
            'https png' => ['https://cdn.example.com/logo.png', true],
            'http jpg' => ['http://cdn.example.com/logo.jpg', true],
            'jpeg' => ['https://cdn.example.com/logo.jpeg', true],
            'svg' => ['https://cdn.example.com/logo.svg', true],
            'uppercase extension' => ['https://cdn.example.com/LOGO.PNG', true],
            'with a query string' => ['https://cdn.example.com/logo.png?v=2', true],
            'empty' => ['', false],
            'relative path' => ['/img/logo.png', false],
            'no extension' => ['https://cdn.example.com/logo', false],
            'unsupported type' => ['https://cdn.example.com/logo.gif', false],
            'javascript scheme' => ['javascript:alert(1)', false],
            'too long' => ['https://cdn.example.com/' . str_repeat('a', 512) . '.png', false],
        ];
    }

    /* ----------------------------------------------------------- guard rails */

    public function testLinkCreationIsBlockedWhenTheFeatureIsOff(): void
    {
        \Configuration::updateValue(Settings::HPP_ENABLED, 0);

        self::assertFalse($this->manager()->canCreate($this->makeOrder()));
    }

    public function testLinkCreationIsBlockedForAZeroAmountOrder(): void
    {
        $order = $this->makeOrder();
        $order->total_paid_tax_incl = 0.0;

        self::assertStringContainsString('no amount', (string) $this->manager()->getBlockReason($order));
    }

    /**
     * Regenerating a link for an order that is already paid would hand the
     * buyer a second charge for the same goods.
     */
    public function testLinkCreationIsBlockedOnceCaptured(): void
    {
        \Db::getInstance()->rows[] = $this->makeLinkRecord([
            'status' => 'approved',
            'captured_amount' => 12500,
        ]);

        self::assertStringContainsString(
            'already been captured',
            (string) $this->manager()->getBlockReason($this->makeOrder())
        );
    }

    public function testLinkCreationIsBlockedOnceTheSessionIsApproved(): void
    {
        \Db::getInstance()->rows[] = $this->makeLinkRecord(['status' => 'approved']);

        self::assertStringContainsString(
            'already been paid',
            (string) $this->manager()->getBlockReason($this->makeOrder())
        );
    }

    /**
     * A link that expired before the buyer got to it has to be replaceable,
     * otherwise the order is stuck with no way to collect payment.
     */
    public function testAnUnpaidLinkCanBeRegenerated(): void
    {
        \Db::getInstance()->rows[] = $this->makeLinkRecord(['status' => 'pending']);

        self::assertNull($this->manager()->getBlockReason($this->makeOrder()));
    }

    /**
     * Payment links are for orders taken by phone or email. An order that came
     * through the storefront already holds a Briqpay session, and a link would
     * open a second one against the same order -- two authorisations, and a
     * buyer who can be charged twice.
     */
    public function testAStorefrontOrderCannotBeGivenAPaymentLink(): void
    {
        \Db::getInstance()->rows[] = [
            'id_order' => 1,
            'session_id' => 'sess-1',
            'status' => 'pending',
            'captured_amount' => 0,
            'refunded_amount' => 0,
            'hpp_created' => null,
        ];

        self::assertStringContainsString(
            'storefront checkout',
            (string) $this->manager()->getBlockReason($this->makeOrder())
        );
    }

    /**
     * An order created in the back office has no Briqpay record at all, which
     * is precisely the case payment links exist for.
     */
    public function testABackOfficeOrderWithNoPaymentCanGetALink(): void
    {
        self::assertNull($this->manager()->getBlockReason($this->makeOrder()));
    }

    /**
     * A record this class wrote, as it looks before the buyer has paid.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function makeLinkRecord(array $overrides = []): array
    {
        return array_merge([
            'id_order' => 1,
            'session_id' => 'sess-1',
            'status' => 'pending',
            'captured_amount' => 0,
            'refunded_amount' => 0,
            'hpp_created' => '2026-09-14 10:00:00',
        ], $overrides);
    }

    private function manager(): HostedPageManager
    {
        $recorded = [];
        $client = new Client(
            'https://api.test',
            'merchant',
            'secret',
            'test',
            $this->makeTransport([], $recorded)
        );

        return new HostedPageManager($client);
    }

    private function makeOrder(): \Order
    {
        $order = new \Order(1);
        $order->id_cart = 1;
        $order->id_lang = 1;
        $order->id_address_invoice = 1;
        $order->id_customer = 1;
        $order->total_paid_tax_incl = 125.0;
        $order->total_paid_tax_excl = 100.0;
        $order->productsDetail = [[
            'product_id' => 1,
            'product_reference' => 'SKU-1',
            'product_name' => 'Test product',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 125.0,
            'unit_price_tax_excl' => 100.0,
        ]];

        return $order;
    }
}
