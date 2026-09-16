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

use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Config\Settings
 */
class SettingsTest extends TestCase
{
    public function testUnsetKeysFallBackToTheDeclaredDefault(): void
    {
        self::assertSame('auto', Settings::getString(Settings::CUSTOMER_TYPE));
        self::assertTrue(Settings::getBool(Settings::DECISION_AMOUNT_CHECK));
        self::assertFalse(Settings::getBool(Settings::LIVE_MODE));
    }

    /**
     * Configuration::get() returns false for both "not set" and "set to 0", so
     * a stored 0 must not be mistaken for "use the default", which for several
     * of these keys is true.
     */
    public function testAStoredFalseBeatsATruthyDefault(): void
    {
        \Configuration::updateValue(Settings::DECISION_AMOUNT_CHECK, 0);

        self::assertFalse(Settings::getBool(Settings::DECISION_AMOUNT_CHECK));
    }

    public function testApiBaseUrlFollowsLiveMode(): void
    {
        self::assertSame(Settings::TEST_BASE_URL, Settings::getApiBaseUrl());

        \Configuration::updateValue(Settings::LIVE_MODE, 1);

        self::assertSame(Settings::LIVE_BASE_URL, Settings::getApiBaseUrl());
    }

    public function testBackOfficeModeIsOneOnPlaygroundAndZeroLive(): void
    {
        self::assertSame(1, Settings::getBackOfficeMode());

        \Configuration::updateValue(Settings::LIVE_MODE, 1);

        self::assertSame(0, Settings::getBackOfficeMode());
    }

    public function testIsConfiguredRequiresBothCredentials(): void
    {
        self::assertFalse(Settings::isConfigured());

        \Configuration::updateValue(Settings::MERCHANT_ID, 'merchant');
        self::assertFalse(Settings::isConfigured(), 'the secret is still missing');

        \Configuration::updateValue(Settings::SECRET, 'secret');
        self::assertTrue(Settings::isConfigured());
    }

    public function testExplicitCustomerTypeOverridesDetection(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_BUSINESS);

        $cart = $this->makeCart();
        \Address::$registry[1] = ['id' => 1, 'company' => '', 'vat_number' => ''];

        self::assertSame(Settings::CUSTOMER_TYPE_BUSINESS, Settings::resolveCustomerType($cart));
    }

    /**
     * An explicit consumer setting must win even on a B2B shop whose buyer has
     * a company on file.
     */
    public function testExplicitConsumerWinsOverACompanyOnFile(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_CONSUMER);
        \Configuration::updateValue('PS_B2B_ENABLE', 1);

        $cart = $this->makeCart();
        \Address::$registry[1] = ['id' => 1, 'company' => 'Acme AB', 'vat_number' => 'SE556677889901'];

        self::assertSame(Settings::CUSTOMER_TYPE_CONSUMER, Settings::resolveCustomerType($cart));
    }

    public function testAutoDetectionReadsTheCompanyField(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_AUTO);

        $cart = $this->makeCart();
        \Address::$registry[1] = ['id' => 1, 'company' => 'Acme AB', 'vat_number' => ''];

        self::assertSame(Settings::CUSTOMER_TYPE_BUSINESS, Settings::resolveCustomerType($cart));
    }

    /**
     * The company field belongs to PrestaShop's standard address form, not to
     * its B2B mode -- that setting governs price lists and outstanding balance.
     *
     * Requiring it before honouring a company name means a buyer who types one
     * in still checks out as a consumer, which is the wrong flow and loses the
     * company on the invoice.
     */
    public function testACompanyNameIsHonouredWithoutPrestaShopB2bMode(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_AUTO);
        \Configuration::updateValue('PS_B2B_ENABLE', 0);

        $cart = $this->makeCart();
        \Address::$registry[1] = ['id' => 1, 'company' => 'Acme AB', 'vat_number' => ''];

        self::assertSame(Settings::CUSTOMER_TYPE_BUSINESS, Settings::resolveCustomerType($cart));
    }

    /**
     * A VAT number on its own must never flip the checkout to business.
     *
     * That field is free text and routinely holds something else entirely -- a
     * postcode typed one box too low is enough to send an ordinary shopper into
     * a company-lookup flow they never asked for.
     */
    public function testAVatNumberAloneDoesNotMakeItABusinessCheckout(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_AUTO);
        \Configuration::updateValue('PS_B2B_ENABLE', 1);

        $cart = $this->makeCart();
        \Address::$registry[1] = ['id' => 1, 'company' => '', 'vat_number' => '11355'];

        self::assertSame(Settings::CUSTOMER_TYPE_CONSUMER, Settings::resolveCustomerType($cart));
    }

    public function testAutoDetectionDefaultsToConsumer(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_AUTO);
        \Configuration::updateValue('PS_B2B_ENABLE', 1);

        $cart = $this->makeCart();
        \Address::$registry[1] = ['id' => 1, 'company' => '   ', 'vat_number' => ''];

        self::assertSame(Settings::CUSTOMER_TYPE_CONSUMER, Settings::resolveCustomerType($cart));
    }

    public function testAutoDetectionWithoutABillingAddressIsConsumer(): void
    {
        \Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_AUTO);
        \Configuration::updateValue('PS_B2B_ENABLE', 1);

        $cart = $this->makeCart();
        $cart->id_address_invoice = 0;

        self::assertSame(Settings::CUSTOMER_TYPE_CONSUMER, Settings::resolveCustomerType($cart));
    }

    public function testConfiguredTermsUrlWins(): void
    {
        \Configuration::updateValue(Settings::TERMS_URL, 'https://shop.example.com/terms');

        self::assertSame(
            'https://shop.example.com/terms',
            Settings::getTermsUrl(\Context::getContext())
        );
    }

    public function testTermsUrlFallsBackToTheShopsConditionsPage(): void
    {
        \Configuration::updateValue(Settings::TERMS_URL, '');
        \Configuration::updateValue('PS_CONDITIONS_CMS_ID', 3);

        self::assertSame(
            'https://shop.example.com/content/3-terms',
            Settings::getTermsUrl(\Context::getContext())
        );
    }

    /**
     * The 1.x module shipped a literal "https://terms.com" in the session
     * payload, which is somebody else's domain.
     */
    public function testTermsUrlNeverPointsAtAThirdPartyPlaceholder(): void
    {
        \Configuration::updateValue(Settings::TERMS_URL, '');

        $url = Settings::getTermsUrl(\Context::getContext());

        self::assertStringStartsWith('https://shop.example.com', $url);
        self::assertStringNotContainsString('terms.com', $url);
    }
}
