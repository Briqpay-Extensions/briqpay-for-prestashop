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
use Briqpay\Payment\Order\OrderManager;
use Briqpay\Payment\Order\StateApplier;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * A merchant who refunds from the order page should see the order say Refunded
 * straight away.
 *
 * Leaving it to the webhook means it does not: delivery has been measured at
 * ninety seconds, and until it lands the order still reads Payment accepted,
 * so the merchant cannot tell whether the refund worked and may well run it
 * again.
 *
 * @covers \Briqpay\Payment\Order\OrderManager
 */
class RefundStateTest extends TestCase
{
    public function testAFullRefundFromTheOrderPageMovesTheOrderImmediately(): void
    {
        $applier = new RecordingStateApplier();
        $this->refund($applier, 0, 3265);

        self::assertSame([[1, 7]], $applier->calls, 'the order should be moved to Refunded');
    }

    public function testAPartialRefundFromTheOrderPageLeavesTheStateAlone(): void
    {
        $applier = new RecordingStateApplier();
        $this->refund($applier, 0, 1000, 1000);

        self::assertSame([[1, 0]], $applier->calls, 'no state should be targeted');
    }

    /**
     * A second partial that finishes the job is a full refund, even though
     * neither half was one on its own.
     */
    public function testASecondPartialThatCompletesTheRefundMovesTheOrder(): void
    {
        $applier = new RecordingStateApplier();
        $this->refund($applier, 1000, 3265, 2265);

        self::assertSame([[1, 7]], $applier->calls);
    }

    /**
     * execute() holds the order lock while it talks to Briqpay, and applying a
     * state takes that same lock. Applying it before the release would deadlock
     * against itself and silently leave the order where it was, so the state is
     * applied only after the operation has finished.
     */
    public function testTheStateIsAppliedAfterTheOperationHasReleasedTheLock(): void
    {
        $applier = new RecordingStateApplier();
        $this->refund($applier, 0, 3265);

        self::assertTrue($applier->called, 'the state was never applied');
        self::assertNotContains(
            true,
            $applier->heldDuring,
            'the order lock was still held when the state was applied; it would deadlock'
        );
    }

    /**
     * Run a refund with the payment row as it stands before and after it.
     *
     * @param int      $refundedBefore Minor units already refunded.
     * @param int      $refundedAfter  Minor units refunded once this one lands.
     * @param int|null $amount         Minor units to refund; null refunds it all.
     */
    private function refund(
        RecordingStateApplier $applier,
        int $refundedBefore,
        int $refundedAfter,
        ?int $amount = null
    ): void {
        $base = [
            'id_order' => 1,
            'id_cart' => 1,
            'session_id' => 'sess-1',
            'status' => 'approved',
            'captured_amount' => 3265,
        ];

        // Read twice: once to find the session and check the operation is
        // allowed, and again afterwards to total the counters.
        \Db::getInstance()->rows[] = array_merge($base, ['refunded_amount' => $refundedBefore]);
        \Db::getInstance()->rows[] = array_merge($base, ['refunded_amount' => $refundedAfter]);

        $recorded = [];
        $client = new Client(
            'https://api.test',
            'merchant',
            'secret',
            'test',
            $this->makeTransport([['status' => 200, 'body' => '{}', 'error' => null]], $recorded)
        );

        $order = new \Order(1);
        $order->total_paid_tax_incl = 32.65;
        $order->total_paid_tax_excl = 26.12;
        $order->current_state = 2;

        (new OrderManager($client, null, null, $applier))->refund($order, $amount);
    }
}

/**
 * Records what it was asked to do, and whether the order lock was still held at
 * the time.
 */
class RecordingStateApplier extends StateApplier
{
    /** @var array<int, array{0:int, 1:int}> */
    public $calls = [];

    /** @var bool */
    public $called = false;

    /** @var array<int, bool> Whether the order lock was held on each call. */
    public $heldDuring = [];

    public function apply(\Order $order, $stateId, $context = '')
    {
        $this->called = true;
        $this->calls[] = [(int) $order->id, (int) $stateId];

        // The stubbed database grants every lock, so replay what the module
        // actually issued. Lock::acquire() inserts through raw SQL and
        // release() deletes by lock_key -- the delete that reaps expired rows
        // filters on date_add instead, and must not be read as a release.
        $held = false;

        foreach (\Db::getInstance()->calls as $call) {
            $first = (string) $call['args'][0];
            $where = isset($call['args'][1]) ? (string) $call['args'][1] : '';

            if ($call['method'] === 'execute' && strpos($first, 'INSERT IGNORE INTO') !== false
                && strpos($first, 'briqpay_lock') !== false) {
                $held = true;
            }

            if ($call['method'] === 'delete' && strpos($first, 'briqpay_lock') !== false
                && strpos($where, 'lock_key') !== false) {
                $held = false;
            }
        }

        $this->heldDuring[] = $held;

        return true;
    }
}
