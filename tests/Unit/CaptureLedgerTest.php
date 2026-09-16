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

use Briqpay\Payment\Order\CaptureLedger;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * A Briqpay refund belongs to a capture, not to the order.
 *
 * With one capture the endpoint infers it, which is why a shop that never
 * captures in parts sees nothing wrong. The moment an order is captured in
 * parts, a refund naming no capture is rejected with HTTP 400 -- reproduced
 * against the playground on an order captured as 47.80 and 35.90 -- and a
 * refund can never draw more than the capture it names.
 *
 * @covers \Briqpay\Payment\Order\CaptureLedger
 */
class CaptureLedgerTest extends TestCase
{
    public function testASingleCaptureNeedsNoCaptureId(): void
    {
        $ledger = new CaptureLedger($this->session([['id' => 'cap-1', 'amount' => 12500]]));

        self::assertFalse($ledger->needsCaptureId());
    }

    public function testSeveralCapturesDo(): void
    {
        $ledger = new CaptureLedger($this->session([
            ['id' => 'cap-1', 'amount' => 4780],
            ['id' => 'cap-2', 'amount' => 3590],
        ]));

        self::assertTrue($ledger->needsCaptureId());
    }

    public function testRefundsAreSubtractedFromTheCaptureTheyBelongTo(): void
    {
        $ledger = new CaptureLedger($this->session(
            [['id' => 'cap-1', 'amount' => 4780], ['id' => 'cap-2', 'amount' => 3590]],
            [['parent' => 'cap-2', 'amount' => 3590]]
        ));

        $captures = $ledger->captures();

        self::assertSame(4780, $captures[0]['remaining']);
        self::assertSame(0, $captures[1]['remaining']);
        self::assertSame(4780, $ledger->refundable());
    }

    /**
     * A refund that fits inside one capture is drawn from that one, not split
     * across several: every split is another refund on the order and another
     * line on the buyer's statement.
     */
    public function testARefundThatFitsOneCaptureIsNotSplit(): void
    {
        $ledger = new CaptureLedger($this->session([
            ['id' => 'cap-1', 'amount' => 4780],
            ['id' => 'cap-2', 'amount' => 3590],
        ]));

        self::assertSame([['id' => 'cap-1', 'amount' => 3590]], $ledger->allocate(3590));
    }

    /**
     * An amount larger than any single capture has to become several refunds.
     */
    public function testARefundLargerThanAnyCaptureIsSplitAcrossThem(): void
    {
        $ledger = new CaptureLedger($this->session([
            ['id' => 'cap-1', 'amount' => 5000],
            ['id' => 'cap-2', 'amount' => 3000],
        ]));

        self::assertSame(
            [['id' => 'cap-1', 'amount' => 5000], ['id' => 'cap-2', 'amount' => 2000]],
            $ledger->allocate(7000)
        );
    }

    public function testMoreThanEverythingCannotBeAllocated(): void
    {
        $ledger = new CaptureLedger($this->session([
            ['id' => 'cap-1', 'amount' => 5000],
            ['id' => 'cap-2', 'amount' => 3000],
        ]));

        self::assertSame([], $ledger->allocate(8001));
        self::assertSame([], $ledger->allocate(0));
    }

    public function testAlreadyRefundedCapturesAreSkipped(): void
    {
        $ledger = new CaptureLedger($this->session(
            [['id' => 'cap-1', 'amount' => 5000], ['id' => 'cap-2', 'amount' => 3000]],
            [['parent' => 'cap-1', 'amount' => 5000]]
        ));

        self::assertSame([['id' => 'cap-2', 'amount' => 3000]], $ledger->allocate(3000));
    }

    /**
     * A pending capture holds no money yet, and a rejected one never did.
     */
    public function testOnlyApprovedCapturesCount(): void
    {
        $session = $this->session([
            ['id' => 'cap-1', 'amount' => 5000],
            ['id' => 'cap-2', 'amount' => 3000, 'status' => 'pending'],
            ['id' => 'cap-3', 'amount' => 1000, 'status' => 'rejected'],
        ]);

        $ledger = new CaptureLedger($session);

        self::assertCount(1, $ledger->captures());
        self::assertSame(5000, $ledger->refundable());
    }

    /** A pending refund has not returned the money, so it does not reduce it. */
    public function testOnlyApprovedRefundsReduceACapture(): void
    {
        $ledger = new CaptureLedger($this->session(
            [['id' => 'cap-1', 'amount' => 5000]],
            [['parent' => 'cap-1', 'amount' => 2000, 'status' => 'pending']]
        ));

        self::assertSame(5000, $ledger->refundable());
    }

    /* -----------------------------------------------------------------
     * Lines belong to the capture that captured them
     * -------------------------------------------------------------- */

    public function testTheCaptureHoldingTheLinesIsFound(): void
    {
        $ledger = new CaptureLedger($this->session([
            ['id' => 'cap-1', 'amount' => 4780, 'cart' => [['reference' => 'demo_1', 'quantity' => 2]]],
            ['id' => 'cap-2', 'amount' => 3590, 'cart' => [['reference' => 'demo_3', 'quantity' => 1]]],
        ]));

        self::assertSame('cap-2', $ledger->captureHolding([['reference' => 'demo_3', 'quantity' => 1]]));
        self::assertSame('cap-1', $ledger->captureHolding([['reference' => 'demo_1', 'quantity' => 2]]));
    }

    /**
     * Lines spread across two captures cannot be one refund, and reporting no
     * holder is how that becomes two.
     */
    public function testLinesSpanningTwoCapturesHaveNoSingleHolder(): void
    {
        $ledger = new CaptureLedger($this->session([
            ['id' => 'cap-1', 'amount' => 4780, 'cart' => [['reference' => 'demo_1', 'quantity' => 2]]],
            ['id' => 'cap-2', 'amount' => 3590, 'cart' => [['reference' => 'demo_3', 'quantity' => 1]]],
        ]));

        self::assertSame('', $ledger->captureHolding([
            ['reference' => 'demo_1', 'quantity' => 1],
            ['reference' => 'demo_3', 'quantity' => 1],
        ]));
    }

    public function testMoreOfALineThanWasCapturedHasNoHolder(): void
    {
        $ledger = new CaptureLedger($this->session([
            ['id' => 'cap-1', 'amount' => 4780, 'cart' => [['reference' => 'demo_1', 'quantity' => 2]]],
        ]));

        self::assertSame('', $ledger->captureHolding([['reference' => 'demo_1', 'quantity' => 3]]));
    }

    /** A capture with nothing left cannot hold a refund, whatever its lines. */
    public function testAFullyRefundedCaptureHoldsNothing(): void
    {
        $ledger = new CaptureLedger($this->session(
            [['id' => 'cap-1', 'amount' => 4780, 'cart' => [['reference' => 'demo_1', 'quantity' => 2]]]],
            [['parent' => 'cap-1', 'amount' => 4780]]
        ));

        self::assertSame('', $ledger->captureHolding([['reference' => 'demo_1', 'quantity' => 2]]));
    }

    public function testASessionWithNoCapturesIsEmpty(): void
    {
        $ledger = new CaptureLedger([]);

        self::assertSame([], $ledger->captures());
        self::assertSame(0, $ledger->refundable());
        self::assertFalse($ledger->needsCaptureId());
    }

    /**
     * @param array<int, array> $captures
     * @param array<int, array> $refunds
     *
     * @return array<string, mixed>
     */
    private function session(array $captures, array $refunds = []): array
    {
        return [
            'data' => [
                'captures' => array_map(static function ($capture) {
                    return [
                        'captureId' => $capture['id'],
                        'amountIncVat' => $capture['amount'],
                        'status' => $capture['status'] ?? 'approved',
                        'cart' => $capture['cart'] ?? [],
                    ];
                }, $captures),
                'refunds' => array_map(static function ($refund) {
                    return [
                        'parentCaptureId' => $refund['parent'],
                        'amountIncVat' => $refund['amount'],
                        'status' => $refund['status'] ?? 'approved',
                    ];
                }, $refunds),
            ],
        ];
    }
}
