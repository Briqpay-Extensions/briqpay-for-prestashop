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
use Briqpay\Payment\Order\StateApplier;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * The same capture or refund reaches the shop two ways -- the button on the
 * order page, and Briqpay's webhook moments later -- so both have to land on
 * the same state. These are the rules they share.
 *
 * @covers \Briqpay\Payment\Order\StateApplier
 */
class StateApplierTest extends TestCase
{
    public function testAFullRefundMovesTheOrderToRefunded(): void
    {
        self::assertSame(7, StateApplier::stateAfterRefund(3265, 3265));
    }

    /**
     * A partial refund is recorded on the payment panel and left alone: the
     * shop cannot know whether more is coming, and marking the whole order
     * Refunded would be a lie.
     */
    public function testAPartialRefundLeavesTheStateAlone(): void
    {
        self::assertSame(0, StateApplier::stateAfterRefund(1000, 3265));
    }

    /**
     * Refunds are summed, so two partials that together cover the capture are
     * a full refund even though neither was on its own.
     */
    public function testRefundsThatAddUpToTheCaptureCountAsFull(): void
    {
        self::assertSame(7, StateApplier::stateAfterRefund(3265, 3000));
    }

    /**
     * Nothing has been paid, so there is nothing to mark refunded -- and
     * without this a zero/zero order would qualify.
     */
    public function testNothingCapturedMeansNothingToRefund(): void
    {
        self::assertSame(0, StateApplier::stateAfterRefund(0, 0));
        self::assertSame(0, StateApplier::stateAfterRefund(500, 0));
    }

    public function testCaptureMovesTheOrderToPaymentAccepted(): void
    {
        \Configuration::updateValue(Settings::AUTO_CAPTURE_STATE, 0);

        self::assertSame(2, StateApplier::stateAfterCapture());
    }

    /**
     * Auto-capturing methods settle without the merchant doing anything, so the
     * shop can optionally carry the order straight on to Shipped.
     */
    public function testCaptureCanCarryTheOrderOnWhenTheShopAsksForIt(): void
    {
        \Configuration::updateValue(Settings::AUTO_CAPTURE_STATE, 1);

        self::assertSame(4, StateApplier::stateAfterCapture());
    }

    public function testCancelMovesTheOrderToCancelled(): void
    {
        self::assertSame(6, StateApplier::stateAfterCancel());
    }

    /**
     * An order already in the target state must not be written again: every
     * change goes through OrderHistory::addWithemail(), so a needless one sends
     * the customer a second "your order was refunded" email.
     */
    public function testAnOrderAlreadyInTheTargetStateIsNotMovedAgain(): void
    {
        $order = new \Order();
        $order->id = 1;
        $order->current_state = 7;

        self::assertFalse((new StateApplier())->apply($order, 7, 'test'));
    }

    public function testThereIsNothingToDoWithoutATargetState(): void
    {
        $order = new \Order();
        $order->id = 1;
        $order->current_state = 2;

        self::assertFalse((new StateApplier())->apply($order, 0, 'test'));
    }
}
