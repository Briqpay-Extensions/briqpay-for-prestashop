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

use Briqpay\Payment\Order\OrderCreator;
use Briqpay\Payment\Tests\Support\TestCase;
use Briqpay\Payment\Webhook\WebhookHandler;

/**
 * Some payment methods settle without the merchant doing anything, and Briqpay
 * says so on the transaction at order time.
 *
 * Ignoring that and waiting for the capture webhook leaves a window -- minutes,
 * in practice -- where the order page reports nothing captured and offers a
 * Capture button for a payment that is already settling itself.
 *
 * @covers \Briqpay\Payment\Order\OrderCreator
 * @covers \Briqpay\Payment\Webhook\WebhookHandler
 */
class AutoCaptureTest extends TestCase
{
    public function testTransactionFlagIsRead(): void
    {
        self::assertTrue(OrderCreator::isAutoCaptured(['autoCaptureEnabled' => true]));
        self::assertFalse(OrderCreator::isAutoCaptured(['autoCaptureEnabled' => false]));
    }

    public function testAMethodThatDoesNotSayIsNotTreatedAsAutomatic(): void
    {
        self::assertFalse(OrderCreator::isAutoCaptured([]));
        self::assertFalse(OrderCreator::isAutoCaptured(['status' => 'approved']));
    }

    public function testSessionIsAutoCapturedFromTheTransactionFlag(): void
    {
        $session = ['data' => ['transactions' => [['autoCaptureEnabled' => true]]]];

        self::assertTrue(WebhookHandler::wasAutoCaptured($session));
    }

    public function testSessionIsAutoCapturedFromTheCaptureEntry(): void
    {
        $session = ['data' => [
            'transactions' => [['autoCaptureEnabled' => false]],
            'captures' => [['autoCaptured' => true, 'amountIncVat' => 4465]],
        ]];

        self::assertTrue(WebhookHandler::wasAutoCaptured($session));
    }

    /**
     * A capture the merchant made by hand arrives on the same webhook. Flagging
     * it automatic would tell the order page the opposite of what happened, and
     * hide the controls for the rest of the amount.
     */
    public function testAManualCaptureIsNotFlaggedAutomatic(): void
    {
        $session = ['data' => [
            'transactions' => [['autoCaptureEnabled' => false]],
            'captures' => [['autoCaptured' => false, 'amountIncVat' => 4465]],
        ]];

        self::assertFalse(WebhookHandler::wasAutoCaptured($session));
    }

    public function testNoCapturesMeansNotAutoCaptured(): void
    {
        self::assertFalse(WebhookHandler::wasAutoCaptured([]));
        self::assertFalse(WebhookHandler::wasAutoCaptured(['data' => ['transactions' => [[]]]]));
    }

    /**
     * A session can already carry its capture by the time the order is created,
     * which is exactly the Worldline case: the order page must not then report
     * nothing captured.
     */
    public function testCapturesAlreadyOnTheSessionAreCounted(): void
    {
        $session = ['data' => ['captures' => [
            ['status' => 'approved', 'autoCaptured' => true, 'amountIncVat' => 4465],
        ]]];

        self::assertSame(4465, WebhookHandler::sumAmounts($session, 'captures'));
        self::assertTrue(WebhookHandler::wasAutoCaptured($session));
    }
}
