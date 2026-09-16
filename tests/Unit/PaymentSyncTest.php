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
use Briqpay\Payment\Order\PaymentSync;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * Captures and refunds reach the order page as webhooks, so the stored record
 * is only as accurate as the last delivery. One that is delayed leaves the page
 * briefly wrong; one that is lost -- no public address in development, an
 * outage in production -- leaves it wrong for good, with nothing the merchant
 * can press to correct it.
 *
 * Re-reading the session when the panel renders makes Briqpay the authority and
 * the stored row a cache.
 *
 * @covers \Briqpay\Payment\Order\PaymentSync
 */
class PaymentSyncTest extends TestCase
{
    public function testCaptureLearnedFromBriqpayIsWrittenBack(): void
    {
        $recorded = [];
        $sync = $this->makeSync([
            ['status' => 200, 'body' => json_encode([
                'data' => [
                    'transactions' => [['status' => 'approved', 'autoCaptureEnabled' => true]],
                    'captures' => [['status' => 'approved', 'amountIncVat' => 4465]],
                ],
            ]), 'error' => null],
        ], $recorded);

        $record = $sync->refresh(17);

        self::assertSame('approved', $record['status']);
        self::assertSame(4465, $record['captured_amount']);
        self::assertSame(1, $record['auto_captured']);

        $written = $this->lastUpdate();
        self::assertSame(4465, $written['captured_amount']);
        self::assertSame(1, $written['auto_captured']);
    }

    /**
     * The overwhelmingly common case is a record that is already correct, which
     * must not cost a write on every page view.
     */
    public function testAnUnchangedPaymentIsNotWritten(): void
    {
        $recorded = [];
        $sync = $this->makeSync([
            ['status' => 200, 'body' => json_encode([
                'data' => [
                    'transactions' => [['status' => 'approved']],
                    'captures' => [['status' => 'approved', 'amountIncVat' => 4465]],
                ],
            ]), 'error' => null],
        ], $recorded, ['status' => 'approved', 'captured_amount' => '4465']);

        $sync->refresh(17);

        self::assertNull($this->lastUpdate(), 'an unchanged record should cost no write');
    }

    /**
     * A refund that was issued in the Briqpay portal never produces a webhook
     * the shop asked for, so the page has to discover it by reading.
     */
    public function testRefundIssuedElsewhereIsPickedUp(): void
    {
        $recorded = [];
        $sync = $this->makeSync([
            ['status' => 200, 'body' => json_encode([
                'data' => [
                    'transactions' => [['status' => 'approved']],
                    'captures' => [['status' => 'approved', 'amountIncVat' => 10000]],
                    'refunds' => [['status' => 'approved', 'amountIncVat' => 2500]],
                ],
            ]), 'error' => null],
        ], $recorded, ['status' => 'approved', 'captured_amount' => '10000', 'refunded_amount' => '0']);

        $record = $sync->refresh(17);

        self::assertSame(2500, $record['refunded_amount']);
    }

    /**
     * The payment method is only known once the shopper has chosen one, so a
     * record written at order creation can still be missing it.
     */
    public function testThePaymentMethodIsFilledInOnceBriqpayKnowsIt(): void
    {
        $recorded = [];
        $sync = $this->makeSync([
            ['status' => 200, 'body' => json_encode([
                'data' => ['transactions' => [[
                    'status' => 'approved',
                    'pspDisplayName' => 'Worldline',
                    'pspIntegrationName' => 'worldline',
                    'reservationId' => 'res-9',
                ]]],
            ]), 'error' => null],
        ], $recorded, ['status' => 'approved', 'psp_display_name' => '', 'psp_name' => '', 'reservation_id' => '']);

        $record = $sync->refresh(17);

        self::assertSame('Worldline', $record['psp_display_name']);
        self::assertSame('worldline', $record['psp_name']);
        self::assertSame('res-9', $record['reservation_id']);
    }

    /**
     * Briqpay stops reporting nothing rather than reporting emptiness, so a
     * blank field in the response must not wipe a value the shop already has.
     */
    public function testAnAbsentPaymentMethodDoesNotEraseTheStoredOne(): void
    {
        $recorded = [];
        $sync = $this->makeSync([
            ['status' => 200, 'body' => '{"data":{"transactions":[{"status":"approved"}]}}', 'error' => null],
        ], $recorded, ['status' => 'approved', 'psp_display_name' => 'Worldline']);

        $record = $sync->refresh(17);

        self::assertSame('Worldline', $record['psp_display_name']);
    }

    /**
     * An order page that cannot reach Briqpay should still render what is
     * known: a merchant looking at a paid order after the credentials were
     * rotated does not need the page to break as well.
     */
    public function testAFailedReadLeavesTheStoredRecordIntact(): void
    {
        $recorded = [];
        $sync = $this->makeSync([
            ['status' => 401, 'body' => '{"message":"unauthorized"}', 'error' => null],
        ], $recorded, ['status' => 'approved', 'captured_amount' => '4465']);

        $record = $sync->refresh(17);

        self::assertSame('approved', $record['status']);
        self::assertSame('4465', $record['captured_amount']);
        self::assertNull($this->lastUpdate());
    }

    public function testAnOrderWithNoBriqpayRecordIsLeftAlone(): void
    {
        $recorded = [];
        $sync = $this->makeSync([], $recorded, null);

        self::assertNull($sync->refresh(17));
        self::assertSame([], $recorded, 'Briqpay should not be called for a non-Briqpay order');
    }

    public function testARecordWithNoSessionIsNotRead(): void
    {
        $recorded = [];
        $sync = $this->makeSync([], $recorded, ['session_id' => '', 'status' => 'pending']);

        $record = $sync->refresh(17);

        self::assertSame('pending', $record['status']);
        self::assertSame([], $recorded);
    }

    public function testTheSessionIsReadByItsOwnId(): void
    {
        $recorded = [];
        $sync = $this->makeSync([
            ['status' => 200, 'body' => '{"data":{"transactions":[]}}', 'error' => null],
        ], $recorded);

        $sync->refresh(17);

        self::assertSame('GET', $recorded[0]['method']);
        self::assertSame('https://api.test/v3/session/sess-1', $recorded[0]['url']);
    }

    /**
     * Build a PaymentSync over a real client, session manager and repository,
     * with the transport and the database stubbed.
     *
     * @param array<int, array>         $responses
     * @param array<int, array>         $recorded
     * @param array<string, mixed>|null $stored    The row the database returns.
     */
    private function makeSync(array $responses, array &$recorded, $stored = ['status' => 'pending']): PaymentSync
    {
        if ($stored !== null) {
            \Db::getInstance()->rows[] = array_merge([
                'id_order' => 17,
                'session_id' => 'sess-1',
                'status' => 'pending',
                'captured_amount' => '0',
                'refunded_amount' => '0',
                'auto_captured' => '0',
            ], $stored);
        }

        $client = new Client('https://api.test', 'merchant', 'secret', 'test', $this->makeTransport($responses, $recorded));

        return new PaymentSync(new SessionManager($client), new OrderRepository());
    }

    /**
     * The data of the last update the repository sent to the database.
     *
     * @return array<string, mixed>|null
     */
    private function lastUpdate(): ?array
    {
        foreach (array_reverse(\Db::getInstance()->calls) as $call) {
            if ($call['method'] === 'update') {
                return $call['args'][1];
            }
        }

        return null;
    }
}
