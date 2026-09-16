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

use Briqpay\Payment\Builder\AddressBuilder;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Builder\AddressBuilder
 */
class AddressBuilderTest extends TestCase
{
    /** @var AddressBuilder */
    private $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new AddressBuilder();

        \Address::$registry[1] = [
            'id' => 1,
            'firstname' => 'Anna',
            'lastname' => 'Svensson',
            'address1' => 'Storgatan 1',
            'address2' => 'c/o Karlsson',
            'postcode' => '11122',
            'city' => 'Stockholm',
            'phone' => '+46701234567',
            'id_country' => 1,
            'id_state' => 0,
            'company' => '',
            'vat_number' => '',
        ];

        \Customer::$registry[1] = ['id' => 1, 'email' => 'anna@example.com'];
    }

    public function testBillingAddressIsMapped(): void
    {
        $billing = $this->builder->buildBilling($this->makeCart());

        self::assertSame('Storgatan 1', $billing['streetAddress']);
        self::assertSame('c/o Karlsson', $billing['streetAddress2']);
        self::assertSame('11122', $billing['zip']);
        self::assertSame('Stockholm', $billing['city']);
        self::assertSame('Anna', $billing['firstName']);
        self::assertSame('Svensson', $billing['lastName']);
        self::assertSame('SE', $billing['country']);
    }

    /**
     * The 1.x module read the email from Address::$other, which is PrestaShop's
     * free-text note field, so the shopper's address comment was sent to
     * Briqpay as their email address.
     */
    public function testEmailComesFromTheCustomerNotTheAddressNoteField(): void
    {
        \Address::$registry[1]['other'] = 'Leave the parcel with the neighbour';

        $billing = $this->builder->buildBilling($this->makeCart());

        self::assertSame('anna@example.com', $billing['email']);
    }

    public function testPhoneFallsBackToTheMobileNumber(): void
    {
        \Address::$registry[1]['phone'] = '';
        \Address::$registry[1]['phone_mobile'] = '+46709999999';

        self::assertSame('+46709999999', $this->builder->buildBilling($this->makeCart())['phoneNumber']);
    }

    public function testRegionIsResolvedFromTheState(): void
    {
        \Address::$registry[1]['id_state'] = 12;
        \State::$names[12] = 'Stockholm County';

        self::assertSame('Stockholm County', $this->builder->buildBilling($this->makeCart())['region']);
    }

    public function testRegionIsEmptyWithoutAState(): void
    {
        self::assertSame('', $this->builder->buildBilling($this->makeCart())['region']);
    }

    public function testMissingAddressReturnsNull(): void
    {
        $cart = $this->makeCart();
        $cart->id_address_invoice = 0;

        self::assertNull($this->builder->buildBilling($cart));
    }

    public function testShippingUsesTheDeliveryAddress(): void
    {
        \Address::$registry[2] = [
            'id' => 2,
            'firstname' => 'Erik',
            'lastname' => 'Larsson',
            'address1' => 'Lillgatan 9',
            'postcode' => '22233',
            'city' => 'Malmo',
            'id_country' => 1,
        ];

        $cart = $this->makeCart();
        $cart->id_address_delivery = 2;

        $shipping = $this->builder->buildShipping($cart);

        self::assertSame('Erik', $shipping['firstName']);
        self::assertSame('Lillgatan 9', $shipping['streetAddress']);
    }

    public function testCompanyIsBuiltFromTheNameAndTheRegistrationNumber(): void
    {
        \Address::$registry[1]['company'] = 'Acme AB';
        \Customer::$registry[1]['siret'] = '5566778899';

        self::assertSame(
            ['name' => 'Acme AB', 'cin' => '5566778899'],
            $this->builder->buildCompany($this->makeCart())
        );
    }

    /**
     * The VAT field is free text and routinely holds something else -- a
     * postcode typed one box too low, most often. Briqpay does not ignore a CIN
     * it cannot validate; it discards the entire company block, leaving a
     * business session with no company at all, the payment module stuck at
     * `not_yet_reached` and the shopper looking at an empty iframe.
     */
    public function testTheVatFieldIsNeverSentAsTheRegistrationNumber(): void
    {
        \Address::$registry[1]['company'] = 'Acme AB';
        \Address::$registry[1]['vat_number'] = 'SE556677889901';

        self::assertSame(['name' => 'Acme AB'], $this->builder->buildCompany($this->makeCart()));
    }

    /**
     * Losing the number is survivable; losing the name is not.
     */
    public function testAnImplausibleRegistrationNumberIsDroppedButTheNameSurvives(): void
    {
        \Address::$registry[1]['company'] = 'Acme AB';
        \Customer::$registry[1]['siret'] = '11355';

        self::assertSame(['name' => 'Acme AB'], $this->builder->buildCompany($this->makeCart()));
    }

    public function testRegistrationNumbersAreAcceptedInTheFormatsBriqpayMarketsUse(): void
    {
        // Sweden 10, Norway 9, Denmark 8, France 14 -- separators and all.
        foreach (['556677-8899', '987 654 321', '12345678', '12345678900011'] as $number) {
            self::assertTrue(
                AddressBuilder::looksLikeCompanyNumber($number),
                $number . ' should be accepted'
            );
        }

        foreach (['11355', '', 'AB', '1234567'] as $number) {
            self::assertFalse(
                AddressBuilder::looksLikeCompanyNumber($number),
                $number . ' should be rejected'
            );
        }
    }

    /**
     * The 1.x module required both fields before sending any company data, so a
     * business buyer who left the VAT number blank was sent as a consumer.
     */
    public function testCompanyNameAloneIsEnough(): void
    {
        \Address::$registry[1]['company'] = 'Acme AB';
        \Address::$registry[1]['vat_number'] = '';

        self::assertSame(['name' => 'Acme AB'], $this->builder->buildCompany($this->makeCart()));
    }

    public function testARegistrationNumberAloneIsEnough(): void
    {
        \Address::$registry[1]['company'] = '';
        \Customer::$registry[1]['siret'] = '5566778899';

        self::assertSame(['cin' => '5566778899'], $this->builder->buildCompany($this->makeCart()));
    }

    public function testConsumerCheckoutHasNoCompanyBlock(): void
    {
        self::assertNull($this->builder->buildCompany($this->makeCart()));
    }

    public function testWhitespaceOnlyCompanyFieldsAreTreatedAsEmpty(): void
    {
        \Address::$registry[1]['company'] = '   ';
        \Customer::$registry[1]['siret'] = "\t";

        self::assertNull($this->builder->buildCompany($this->makeCart()));
    }

    public function testEmailIsEmptyForAGuestCartWithoutACustomer(): void
    {
        $cart = $this->makeCart();
        $cart->id_customer = 0;

        self::assertSame('', $this->builder->buildBilling($cart)['email']);
    }
}
