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

use Briqpay\Payment\Builder\SessionPayloadBuilder;
use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Builder\SessionPayloadBuilder
 */
class SessionPayloadBuilderTest extends TestCase
{
    /** @var SessionPayloadBuilder */
    private $builder;

    /** @var \Context */
    private $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new SessionPayloadBuilder();

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

        $this->context = \Context::getContext();
        $this->context->cart = $this->makeCart(
            ['1:3' => 125.0, '0:3' => 100.0],
            [$this->makeProduct()]
        );
    }

    public function testCreatePayloadDeclaresAOneTimePaymentProduct(): void
    {
        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('payment', $payload['product']['type']);
        self::assertSame('payment_one_time', $payload['product']['intent']);
        self::assertSame('consumer', $payload['customerType']);
        self::assertSame(['payment'], $payload['modules']['loadModules']);
    }

    public function testCreatePayloadCarriesTheCartReference(): void
    {
        $this->context->cart->id = 77;

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('77', $payload['references']['cartId']);
    }

    /**
     * The 1.x module only subscribed to order_status and capture_status, so a
     * refund made in the Briqpay dashboard never reached PrestaShop.
     */
    public function testAllThreeWebhookEventsAreSubscribed(): void
    {
        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        $events = array_column($payload['hooks'], 'eventType');

        self::assertContains('order_status', $events);
        self::assertContains('capture_status', $events);
        self::assertContains('refund_status', $events);
    }

    public function testWebhooksAllPointAtTheModulesEndpoint(): void
    {
        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        foreach ($payload['hooks'] as $hook) {
            self::assertStringContainsString('briqpay_payment_module/webhook', $hook['url']);
            self::assertSame('POST', $hook['method']);
        }
    }

    /**
     * Briqpay substitutes the placeholder when it redirects the shopper back,
     * so it must survive URL building rather than being percent-encoded.
     */
    public function testRedirectUrlKeepsTheSessionPlaceholderIntact(): void
    {
        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertStringContainsString('{briqpay_session}', $payload['urls']['redirect']);
        self::assertStringNotContainsString('%7Bbriqpay_session%7D', $payload['urls']['redirect']);
    }

    public function testCountryPrefersTheBillingAddressOverTheShopDefault(): void
    {
        $this->context->country->iso_code = 'DE';
        \Address::$registry[1]['id_country'] = 2; // NO

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('NO', $payload['country']);
    }

    public function testCountryFallsBackToTheShopWhenThereIsNoAddress(): void
    {
        $this->context->cart->id_address_invoice = 0;
        $this->context->country->iso_code = 'DE';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('DE', $payload['country']);
    }

    /**
     * PrestaShop 1.7.0-1.7.5 has no Language::$locale, so the iso code is all
     * there is -- and a bare "sv" is not a locale Briqpay accepts.
     */
    public function testLocaleFallsBackToTheLanguageIsoCode(): void
    {
        $this->context->language->locale = '';
        $this->context->language->language_code = '';
        $this->context->language->iso_code = 'sv';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('sv-se', $payload['locale']);
    }

    /**
     * PrestaShop ships English as en-US, which Briqpay does not recognise.
     */
    public function testEnglishIsSentAsEnGb(): void
    {
        $this->context->language->locale = 'en-US';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('en-gb', $payload['locale']);
    }

    public function testSwedishLocaleIsLowercased(): void
    {
        $this->context->language->locale = 'sv-SE';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('sv-se', $payload['locale']);
    }

    public function testUpdatePayloadOnlyContainsMutableData(): void
    {
        $payload = $this->builder->buildUpdatePayload($this->context);

        self::assertSame(['data'], array_keys($payload));
        self::assertArrayHasKey('order', $payload['data']);
        self::assertArrayNotHasKey('hooks', $payload);
        self::assertArrayNotHasKey('product', $payload);
    }

    /**
     * The decision step has to be requested in the session payload; it is not
     * on by default. Without it Briqpay never pauses, make_decision never
     * fires, and every server-side check in the decision controller is dead
     * code -- an order can then be placed with the terms box unticked.
     *
     * The nesting is exact: modules.payment.* is rejected by the API with
     * "body.modules has additional properties".
     */
    public function testCreatePayloadRequestsTheDecisionStep(): void
    {
        \Configuration::updateValue(Settings::ENFORCE_TERMS, 1);

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertTrue(
            $payload['modules']['config']['payment']['decision']['enabled'],
            'the decision step must be requested under modules.config.payment'
        );
        self::assertArrayNotHasKey(
            'payment',
            $payload['modules'],
            'modules.payment is rejected by the Briqpay API'
        );
    }

    /**
     * The decision step costs the shopper a round trip, so it is only asked for
     * when there is something to validate.
     */
    public function testDecisionStepIsNotRequestedWhenEveryCheckIsOff(): void
    {
        \Configuration::updateValue(Settings::ENFORCE_TERMS, 0);
        \Configuration::updateValue(Settings::DECISION_AMOUNT_CHECK, 0);
        \Configuration::updateValue(Settings::DECISION_ADDRESS_CHECK, 0);

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertFalse($payload['modules']['config']['payment']['decision']['enabled']);
    }

    /**
     * @dataProvider decisionTriggerProvider
     */
    public function testAnySingleCheckRequestsTheDecisionStep(string $enabledSetting): void
    {
        foreach ([Settings::ENFORCE_TERMS, Settings::DECISION_AMOUNT_CHECK, Settings::DECISION_ADDRESS_CHECK] as $key) {
            \Configuration::updateValue($key, $key === $enabledSetting ? 1 : 0);
        }

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertTrue($payload['modules']['config']['payment']['decision']['enabled']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function decisionTriggerProvider(): array
    {
        return [
            'terms' => [Settings::ENFORCE_TERMS],
            'amounts' => [Settings::DECISION_AMOUNT_CHECK],
            'addresses' => [Settings::DECISION_ADDRESS_CHECK],
        ];
    }

    public function testCompanyIsIncludedForBusinessCarts(): void
    {
        \Address::$registry[1]['company'] = 'Acme AB';
        \Customer::$registry[1]['siret'] = '5566778899';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_BUSINESS);

        self::assertSame('Acme AB', $payload['data']['company']['name']);
        self::assertSame('5566778899', $payload['data']['company']['cin']);
    }

    /**
     * The company name is what makes a business session usable. Sending a VAT
     * number as the registration number costs the whole block -- Briqpay drops
     * it rather than the offending field -- and the shopper is left with an
     * iframe that never loads a payment method.
     */
    public function testAVatNumberDoesNotCostTheBusinessSessionItsCompany(): void
    {
        \Address::$registry[1]['company'] = 'Acme AB';
        \Address::$registry[1]['vat_number'] = '11355';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_BUSINESS);

        self::assertSame(['name' => 'Acme AB'], $payload['data']['company']);
    }

    /**
     * A consumer session must never carry company data.
     *
     * Briqpay cannot reconcile customerType "consumer" with a company block: the
     * checkout stalls on "Waiting for address details" and offers no payment
     * methods. A stray VAT number on the billing address -- a mistyped postcode,
     * say -- is enough to trigger it.
     */
    public function testConsumerCartsNeverCarryCompanyData(): void
    {
        \Address::$registry[1]['company'] = '';
        \Address::$registry[1]['vat_number'] = '11355';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertArrayNotHasKey('company', $payload['data']);
    }

    public function testConsumerCartsDropCompanyEvenWithAFullCompanyOnFile(): void
    {
        \Address::$registry[1]['company'] = 'Acme AB';
        \Address::$registry[1]['vat_number'] = 'SE556677889901';

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertArrayNotHasKey('company', $payload['data']);
        self::assertArrayHasKey('billing', $payload['data'], 'addresses are still sent');
    }

    /**
     * The 1.x module built the company block only in the update path, so a
     * session created for a business buyer started out without it.
     */
    public function testCompanyIsPresentInBothCreateAndUpdatePayloads(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_BUSINESS);
        \Address::$registry[1]['company'] = 'Acme AB';

        $create = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_BUSINESS);
        $update = $this->builder->buildUpdatePayload($this->context);

        self::assertArrayHasKey('company', $create['data']);
        self::assertArrayHasKey('company', $update['data']);
    }

    /**
     * The update path resolves the customer type itself, so it must drop the
     * company block on a consumer shop exactly as the create path does.
     */
    public function testUpdatePayloadAlsoDropsCompanyForConsumers(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_CONSUMER);
        \Address::$registry[1]['company'] = 'Acme AB';
        \Address::$registry[1]['vat_number'] = '11355';

        $update = $this->builder->buildUpdatePayload($this->context);

        self::assertArrayNotHasKey('company', $update['data']);
    }

    public function testFingerprintIsStableForIdenticalData(): void
    {
        $a = $this->builder->buildUpdatePayload($this->context);
        $b = $this->builder->buildUpdatePayload($this->context);

        self::assertSame($this->builder->fingerprint($a['data']), $this->builder->fingerprint($b['data']));
    }

    public function testFingerprintChangesWhenTheCartTotalChanges(): void
    {
        $before = $this->builder->fingerprint($this->builder->buildUpdatePayload($this->context)['data']);

        $this->context->cart->totals = ['1:3' => 250.0, '0:3' => 200.0];
        $after = $this->builder->fingerprint($this->builder->buildUpdatePayload($this->context)['data']);

        self::assertNotSame($before, $after);
    }

    /**
     * Key order is an implementation detail of however the payload was built;
     * reordering it must not look like a change, or every cart refresh would
     * trigger a needless PATCH to Briqpay.
     */
    public function testFingerprintIgnoresKeyOrder(): void
    {
        $one = ['order' => ['amountIncVat' => 12500, 'currency' => 'SEK']];
        $two = ['order' => ['currency' => 'SEK', 'amountIncVat' => 12500]];

        self::assertSame($this->builder->fingerprint($one), $this->builder->fingerprint($two));
    }

    public function testFingerprintRespectsListOrder(): void
    {
        $one = ['cart' => [['reference' => 'a'], ['reference' => 'b']]];
        $two = ['cart' => [['reference' => 'b'], ['reference' => 'a']]];

        self::assertNotSame(
            $this->builder->fingerprint($one),
            $this->builder->fingerprint($two),
            'line item order is meaningful'
        );
    }

    public function testTermsUrlIsTakenFromSettings(): void
    {
        \Configuration::updateValue(Settings::TERMS_URL, 'https://shop.example.com/terms');

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertSame('https://shop.example.com/terms', $payload['urls']['terms']);
    }

    public function testAddressesAreOmittedWhenTheCartHasNone(): void
    {
        $this->context->cart->id_address_invoice = 0;
        $this->context->cart->id_address_delivery = 0;

        $payload = $this->builder->buildCreatePayload($this->context, Settings::CUSTOMER_TYPE_CONSUMER);

        self::assertArrayNotHasKey('billing', $payload['data']);
        self::assertArrayNotHasKey('shipping', $payload['data']);
        self::assertArrayHasKey('order', $payload['data']);
    }
}
