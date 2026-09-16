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

use Briqpay\Payment\Tests\Support\TestCase;
use Briqpay\Payment\Webhook\WebhookHandler;

/**
 * @covers \Briqpay\Payment\Webhook\WebhookHandler
 */
class WebhookHandlerTest extends TestCase
{
    /**
     * @dataProvider sessionIdProvider
     */
    public function testExtractSessionId(array $payload, string $expected): void
    {
        self::assertSame($expected, WebhookHandler::extractSessionId($payload));
    }

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public function sessionIdProvider(): array
    {
        return [
            'canonical key' => [['sessionId' => 'abc'], 'abc'],
            'snake case' => [['session_id' => 'abc'], 'abc'],
            'bare id' => [['id' => 'abc'], 'abc'],
            'prefers canonical' => [['sessionId' => 'first', 'id' => 'second'], 'first'],
            'missing' => [['event' => 'order_status'], ''],
            'empty string' => [['sessionId' => ''], ''],
            'non-string is ignored' => [['sessionId' => 123], ''],
        ];
    }

    public function testSumAmountsTotalsApprovedEntries(): void
    {
        $session = ['data' => ['captures' => [
            ['status' => 'approved', 'amountIncVat' => 5000],
            ['status' => 'approved', 'amountIncVat' => 2500],
        ]]];

        self::assertSame(7500, WebhookHandler::sumAmounts($session, 'captures'));
    }

    /**
     * A rejected capture moved no money, so counting it would leave the shop
     * believing it had been paid.
     */
    public function testSumAmountsExcludesRejectedEntries(): void
    {
        $session = ['data' => ['captures' => [
            ['status' => 'approved', 'amountIncVat' => 5000],
            ['status' => 'rejected', 'amountIncVat' => 9999],
            ['status' => 'pending', 'amountIncVat' => 1111],
        ]]];

        self::assertSame(5000, WebhookHandler::sumAmounts($session, 'captures'));
    }

    public function testSumAmountsTreatsAMissingStatusAsApproved(): void
    {
        $session = ['data' => ['refunds' => [['amountIncVat' => 3000]]]];

        self::assertSame(3000, WebhookHandler::sumAmounts($session, 'refunds'));
    }

    public function testSumAmountsFallsBackToTheAmountKey(): void
    {
        $session = ['data' => ['refunds' => [['status' => 'approved', 'amount' => 1500]]]];

        self::assertSame(1500, WebhookHandler::sumAmounts($session, 'refunds'));
    }

    public function testSumAmountsHandlesAMissingCollection(): void
    {
        self::assertSame(0, WebhookHandler::sumAmounts([], 'captures'));
        self::assertSame(0, WebhookHandler::sumAmounts(['data' => []], 'captures'));
        self::assertSame(0, WebhookHandler::sumAmounts(['data' => ['captures' => 'nonsense']], 'captures'));
    }

    public function testSumAmountsIgnoresMalformedEntries(): void
    {
        $session = ['data' => ['captures' => [
            'not-an-array',
            ['status' => 'approved', 'amountIncVat' => 1000],
        ]]];

        self::assertSame(1000, WebhookHandler::sumAmounts($session, 'captures'));
    }
}
