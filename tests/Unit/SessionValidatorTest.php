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
use Briqpay\Payment\Validation\SessionValidator;

/**
 * @covers \Briqpay\Payment\Validation\SessionValidator
 * @covers \Briqpay\Payment\Validation\ValidationResult
 */
class SessionValidatorTest extends TestCase
{
    public function testMatchingAmountsPass(): void
    {
        $result = SessionValidator::compareAmounts(12500, 10000, 12500, 10000);

        self::assertTrue($result->isValid());
        self::assertSame('', $result->getMessage());
    }

    public function testGrossMismatchIsRejected(): void
    {
        $result = SessionValidator::compareAmounts(12500, 10000, 9900, 10000);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('including VAT', $result->getMessage());
    }

    public function testNetMismatchIsRejected(): void
    {
        $result = SessionValidator::compareAmounts(12500, 10000, 12500, 9000);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('excluding VAT', $result->getMessage());
    }

    public function testMissingSessionTotalsAreRejected(): void
    {
        self::assertFalse(SessionValidator::compareAmounts(12500, 10000, null, null)->isValid());
    }

    /**
     * A single minor unit of difference means real money is at stake, so the
     * comparison must stay exact.
     */
    public function testAmountComparisonIsExact(): void
    {
        self::assertFalse(SessionValidator::compareAmounts(12500, 10000, 12501, 10000)->isValid());
    }

    public function testIdenticalAddressesPass(): void
    {
        $address = $this->address();

        self::assertTrue(SessionValidator::compareAddresses($address, $address)->isValid());
    }

    /**
     * Briqpay's hosted fields normalise casing and spacing. The 1.x module
     * compared with !== and rejected legitimate purchases over "Storgatan  1"
     * versus "Storgatan 1".
     */
    public function testAddressComparisonIgnoresCaseAndWhitespace(): void
    {
        $shop = $this->address(['streetAddress' => 'Storgatan 1', 'city' => 'Stockholm']);
        $session = $this->address(['streetAddress' => '  storgatan   1 ', 'city' => 'STOCKHOLM']);

        self::assertTrue(SessionValidator::compareAddresses($shop, $session)->isValid());
    }

    public function testGenuinelyDifferentStreetIsRejected(): void
    {
        $shop = $this->address(['streetAddress' => 'Storgatan 1']);
        $session = $this->address(['streetAddress' => 'Lillgatan 9']);

        $result = SessionValidator::compareAddresses($shop, $session, 'billing');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('billing', $result->getMessage());
        self::assertStringContainsString('streetAddress', $result->getMessage());
    }

    public function testDifferentCountryIsRejected(): void
    {
        $shop = $this->address(['country' => 'SE']);
        $session = $this->address(['country' => 'NO']);

        self::assertFalse(SessionValidator::compareAddresses($shop, $session)->isValid());
    }

    /**
     * An empty value on one side means that system never collected the field,
     * which is not evidence of tampering.
     */
    public function testFieldsMissingOnEitherSideAreSkipped(): void
    {
        $shop = $this->address(['zip' => '11122']);
        $session = $this->address(['zip' => '']);

        self::assertTrue(SessionValidator::compareAddresses($shop, $session)->isValid());
        self::assertTrue(SessionValidator::compareAddresses($session, $shop)->isValid());
    }

    public function testFieldsOutsideTheComparedSetAreIgnored(): void
    {
        $shop = $this->address(['phoneNumber' => '+46701111111']);
        $session = $this->address(['phoneNumber' => '+46702222222']);

        self::assertTrue(
            SessionValidator::compareAddresses($shop, $session)->isValid(),
            'phone formatting differs too often between systems to gate a purchase on'
        );
    }

    public function testValidateSkipsAmountsWhenTheCheckIsDisabled(): void
    {
        \Configuration::updateValue(Settings::DECISION_AMOUNT_CHECK, 0);
        \Configuration::updateValue(Settings::DECISION_ADDRESS_CHECK, 0);

        $cart = $this->makeCart(['1:3' => 125.0, '0:3' => 100.0], [$this->makeProduct()]);

        $result = (new SessionValidator())->validate($cart, [
            'data' => ['order' => ['amountIncVat' => 999999, 'amountExVat' => 999999]],
        ]);

        self::assertTrue($result->isValid());
    }

    public function testValidateEnforcesAmountsWhenEnabled(): void
    {
        \Configuration::updateValue(Settings::DECISION_AMOUNT_CHECK, 1);
        \Configuration::updateValue(Settings::DECISION_ADDRESS_CHECK, 0);

        $cart = $this->makeCart(['1:3' => 125.0, '0:3' => 100.0], [$this->makeProduct()]);

        $result = (new SessionValidator())->validate($cart, [
            'data' => ['order' => ['amountIncVat' => 999999, 'amountExVat' => 999999]],
        ]);

        self::assertFalse($result->isValid());
    }

    public function testValidateRejectsASessionWithNoOrderData(): void
    {
        \Configuration::updateValue(Settings::DECISION_AMOUNT_CHECK, 1);

        $cart = $this->makeCart(['1:3' => 125.0, '0:3' => 100.0], [$this->makeProduct()]);

        self::assertFalse((new SessionValidator())->validate($cart, [])->isValid());
    }

    /**
     * @return array<string, string>
     */
    private function address(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Anna',
            'lastName' => 'Svensson',
            'streetAddress' => 'Storgatan 1',
            'zip' => '11122',
            'city' => 'Stockholm',
            'country' => 'SE',
            'email' => 'anna@example.com',
            'phoneNumber' => '+46701234567',
        ], $overrides);
    }
}
