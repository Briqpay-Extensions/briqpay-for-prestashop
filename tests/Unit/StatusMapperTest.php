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

use Briqpay\Payment\Order\StatusMapper;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Order\StatusMapper
 */
class StatusMapperTest extends TestCase
{
    /**
     * @dataProvider normalisationProvider
     */
    public function testNormalise(string $input, string $expected): void
    {
        self::assertSame($expected, StatusMapper::normalise($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function normalisationProvider(): array
    {
        return [
            'order event pending' => ['order_pending', StatusMapper::STATUS_PENDING],
            'order event approved' => ['order_approved_not_captured', StatusMapper::STATUS_APPROVED],
            'order event rejected' => ['order_rejected', StatusMapper::STATUS_REJECTED],
            'order event cancelled' => ['order_cancelled', StatusMapper::STATUS_CANCELLED],
            'transaction approved' => ['approved', StatusMapper::STATUS_APPROVED],
            'transaction captured' => ['captured', StatusMapper::STATUS_APPROVED],
            'transaction rejected' => ['rejected', StatusMapper::STATUS_REJECTED],
            'american spelling' => ['canceled', StatusMapper::STATUS_CANCELLED],
            'mixed case' => ['APPROVED', StatusMapper::STATUS_APPROVED],
            'padded' => ['  approved  ', StatusMapper::STATUS_APPROVED],
            'unknown falls back to pending' => ['something_new', StatusMapper::STATUS_PENDING],
            'empty falls back to pending' => ['', StatusMapper::STATUS_PENDING],
        ];
    }

    public function testApprovedMapsToThePaymentAcceptedState(): void
    {
        self::assertSame(2, StatusMapper::toOrderState('approved'));
    }

    public function testRejectedMapsToTheErrorState(): void
    {
        self::assertSame(8, StatusMapper::toOrderState('order_rejected'));
    }

    public function testCancelledMapsToTheCancelledState(): void
    {
        self::assertSame(6, StatusMapper::toOrderState('order_cancelled'));
    }

    /**
     * States are resolved through Configuration, never hardcoded. A shop that
     * renumbered its order states must still get the right one -- the 1.x
     * module returned literal 2/3/5/6/7 and moved orders to whatever happened
     * to occupy those ids.
     */
    public function testStatesFollowTheShopsOwnConfiguration(): void
    {
        \Configuration::updateValue('PS_OS_PAYMENT', 42);
        \Configuration::updateValue('PS_OS_CANCELED', 77);

        self::assertSame(42, StatusMapper::toOrderState('approved'));
        self::assertSame(77, StatusMapper::toOrderState('cancelled'));
    }

    public function testPendingUsesTheModulesOwnAwaitingState(): void
    {
        \OrderState::$existing[55] = true;
        \Configuration::updateValue(StatusMapper::CONFIG_AWAITING_STATE, 55);

        self::assertSame(55, StatusMapper::toOrderState('order_pending'));
    }

    public function testAwaitingStateFallsBackWhenTheCustomStateWasDeleted(): void
    {
        \Configuration::updateValue(StatusMapper::CONFIG_AWAITING_STATE, 999);

        // 999 is not in OrderState::$existing, so the object will not load.
        self::assertSame(3, StatusMapper::getAwaitingState(), 'falls back to PS_OS_PREPARATION');
    }

    public function testIsApproved(): void
    {
        self::assertTrue(StatusMapper::isApproved('approved'));
        self::assertTrue(StatusMapper::isApproved('order_approved_not_captured'));
        self::assertFalse(StatusMapper::isApproved('order_pending'));
        self::assertFalse(StatusMapper::isApproved('rejected'));
    }

    public function testIsFinal(): void
    {
        self::assertTrue(StatusMapper::isFinal('rejected'));
        self::assertTrue(StatusMapper::isFinal('order_cancelled'));
        self::assertFalse(StatusMapper::isFinal('approved'));
        self::assertFalse(StatusMapper::isFinal('order_pending'));
    }
}
