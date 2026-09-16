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

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Order\OrderManager;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * Every capture, refund and cancel a merchant can reach from the order page,
 * in the states an order can be in when they press it.
 *
 * Each case here was first run against the Briqpay playground with real money
 * movements; these pin what came back, so the same walk does not have to be
 * repeated by hand.
 *
 * @covers \Briqpay\Payment\Order\OrderManager
 */
class OperationMatrixTest extends TestCase
{
    private const TOTAL = 12500;

    /* -----------------------------------------------------------------
     * Capture
     * -------------------------------------------------------------- */

    public function testCapturingTheWholeOrderIsAllowedWhenNothingIsCaptured(): void
    {
        $recorded = [];
        $this->manager($recorded)->capture($this->order());

        self::assertStringContainsString('/order/capture', end($recorded)['url']);
    }

    public function testCapturingAgainOnceFullyCapturedIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('would exceed the order total');

        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL])->capture($this->order(), self::TOTAL);
    }

    public function testCapturingMoreThanTheOrderTotalIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('would exceed the order total');

        $recorded = [];
        $this->manager($recorded)->capture($this->order(), self::TOTAL + 100);
    }

    public function testCapturingZeroIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('greater than zero');

        $recorded = [];
        $this->manager($recorded)->capture($this->order(), 0);
    }

    public function testCapturingANegativeAmountIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('greater than zero');

        $recorded = [];
        $this->manager($recorded)->capture($this->order(), -1000);
    }

    /**
     * The remainder, not the original total.
     *
     * Reading a whole-order capture as the order total means that once any part
     * of an order is captured, the Capture button can never succeed again: it
     * asks for 125 on an order with 100 left and is refused for exceeding it.
     * The amount asked for here is 100, which the single line cannot express --
     * so the refusal names the real constraint rather than an arithmetic one.
     */
    public function testCapturingTheRestAsksForWhatIsLeftNotTheOriginalTotal(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('100.00 does not match any combination');

        $recorded = [];
        $this->manager($recorded, ['captured_amount' => 2500])->capture($this->order());
    }

    /**
     * Where the lines do allow it, capturing the rest works.
     *
     * Briqpay captures whole order lines, so an order of two lines can be
     * captured one line at a time -- and this is the shape a merchant shipping
     * part of an order actually has.
     */
    public function testTheRestOfAMultiLineOrderCanBeCaptured(): void
    {
        $recorded = [];
        $this->manager($recorded, ['captured_amount' => 0], $this->twoLineSession())
            ->capture($this->order(), 2500);

        $sent = json_decode(end($recorded)['body'], true);

        self::assertSame(2500, $sent['data']['order']['amountIncVat']);
        self::assertCount(1, $sent['data']['order']['cart']);
        self::assertSame('BQ-TEST-B', $sent['data']['order']['cart'][0]['reference']);
    }

    /**
     * A session whose cart is two lines: 100.00 and 25.00 including VAT.
     */
    private function twoLineSession(): string
    {
        return json_encode([
            'data' => [
                'order' => [
                    'amountIncVat' => self::TOTAL,
                    'currency' => 'SEK',
                    'cart' => [
                        ['productType' => 'physical', 'reference' => 'BQ-TEST-A',
                         'quantity' => 1, 'unitPrice' => 8000, 'taxRate' => 2500],
                        ['productType' => 'physical', 'reference' => 'BQ-TEST-B',
                         'quantity' => 1, 'unitPrice' => 2000, 'taxRate' => 2500],
                    ],
                ],
                'captures' => [],
                'refunds' => [],
            ],
        ]);
    }

    /**
     * "Capture the rest" after a partial capture, when VAT rounding makes the
     * real remaining lines sum to one minor unit more than PrestaShop's order
     * total says is left.
     *
     * Reproduced live on a real German (19% VAT) order: three lines whose
     * independently-rounded incVat summed to 88.02 while the order total,
     * rounded once on the aggregate ex-VAT, was 88.01. After capturing the
     * first line (45.51), "capture everything left" computed a target of
     * 42.50 from the order total -- one cent short of what the two remaining
     * lines (34.18 + 8.33 = 42.51) actually sum to -- and no combination of
     * real lines matched it, so Briqpay refused an amount that was, in
     * truth, entirely legitimate.
     */
    public function testCapturingTheRestAfterAPartialCaptureToleratesVatRoundingDrift(): void
    {
        $recorded = [];
        $session = [
            'data' => [
                'order' => [
                    'amountIncVat' => 8801, // PrestaShop's order total: 8801, not 8802.
                    'currency' => 'EUR',
                    'cart' => [
                        ['productType' => 'physical', 'reference' => 'demo_1', 'quantity' => 2, 'unitPrice' => 1912, 'taxRate' => 1900],
                        ['productType' => 'physical', 'reference' => 'demo_3', 'quantity' => 1, 'unitPrice' => 2872, 'taxRate' => 1900],
                        ['productType' => 'shipping_fee', 'reference' => '2', 'quantity' => 1, 'unitPrice' => 700, 'taxRate' => 1900],
                    ],
                ],
                'captures' => [],
                'refunds' => [],
            ],
        ];

        \Db::getInstance()->rows[] = [
            'id_order' => 1, 'session_id' => 'sess-1', 'status' => 'approved', 'captured_amount' => 4551, 'refunded_amount' => 0,
        ];
        \Db::getInstance()->rows[] = [
            'id_order' => 1, 'session_id' => 'sess-1', 'status' => 'approved', 'captured_amount' => 4551, 'refunded_amount' => 0,
        ];

        $client = new Client('https://api.test', 'm', 's', 't', $this->makeTransport([
            ['status' => 200, 'body' => json_encode($session), 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded));

        $order = new \Order(1);
        $order->total_paid_tax_incl = 88.01; // the order total, one cent under the true remaining lines
        $order->total_paid_tax_excl = 73.96;
        $order->current_state = 2;

        (new OrderManager($client))->capture($order); // null: "capture everything left"

        $sent = json_decode(end($recorded)['body'], true);
        $cart = $sent['data']['order']['cart'];

        self::assertCount(2, $cart, 'both remaining lines should be captured');
        self::assertSame(['demo_3', '2'], array_column($cart, 'reference'));
        self::assertSame(4251, $sent['data']['order']['amountIncVat'], 'the true sum of the remaining lines, not the order-total-derived 4250');
    }

    /**
     * The stored counter must match what was actually sent, or it silently
     * disagrees with Briqpay until the next sync corrects it.
     */
    public function testTheCapturedCounterRecordsWhatWasActuallySentNotTheOrderTotalEstimate(): void
    {
        $session = [
            'data' => [
                'order' => [
                    'amountIncVat' => 8801,
                    'currency' => 'EUR',
                    'cart' => [
                        ['productType' => 'physical', 'reference' => 'demo_1', 'quantity' => 2, 'unitPrice' => 1912, 'taxRate' => 1900],
                        ['productType' => 'physical', 'reference' => 'demo_3', 'quantity' => 1, 'unitPrice' => 2872, 'taxRate' => 1900],
                        ['productType' => 'shipping_fee', 'reference' => '2', 'quantity' => 1, 'unitPrice' => 700, 'taxRate' => 1900],
                    ],
                ],
                'captures' => [],
                'refunds' => [],
            ],
        ];

        \Db::getInstance()->rows[] = [
            'id_order' => 1, 'session_id' => 'sess-1', 'status' => 'approved', 'captured_amount' => 4551, 'refunded_amount' => 0,
        ];
        \Db::getInstance()->rows[] = [
            'id_order' => 1, 'session_id' => 'sess-1', 'status' => 'approved', 'captured_amount' => 4551, 'refunded_amount' => 0,
        ];

        $recorded = [];
        $client = new Client('https://api.test', 'm', 's', 't', $this->makeTransport([
            ['status' => 200, 'body' => json_encode($session), 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded));

        $order = new \Order(1);
        $order->total_paid_tax_incl = 88.01;
        $order->total_paid_tax_excl = 73.96;
        $order->current_state = 2;

        (new OrderManager($client))->capture($order);

        $incrementCall = null;
        foreach (\Db::getInstance()->calls as $call) {
            if ($call['method'] === 'execute' && strpos((string) $call['args'][0], 'captured_amount') !== false) {
                $incrementCall = (string) $call['args'][0];
            }
        }

        self::assertNotNull($incrementCall);
        self::assertStringContainsString('+ 4251', $incrementCall, 'the counter must add the true 42.51, not the order-total-derived 42.50');
    }

    /* -----------------------------------------------------------------
     * Refund
     * -------------------------------------------------------------- */

    public function testRefundingBeforeAnythingIsCapturedIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Nothing has been captured');

        $recorded = [];
        $this->manager($recorded)->refund($this->order());
    }

    public function testAPartialRefundIsAllowed(): void
    {
        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL])->refund($this->order(), 2500);

        self::assertStringContainsString('/order/refund', end($recorded)['url']);
    }

    public function testRefundingMoreThanWasCapturedIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('would exceed the captured amount');

        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL])->refund($this->order(), self::TOTAL + 100);
    }

    /**
     * Refunding "everything" after a partial refund means the rest of it.
     *
     * This is the case that failed live: 25 refunded of 125, and the full
     * refund then asked for 125 again and was refused for exceeding the
     * capture -- so the button could never be used again.
     */
    public function testRefundingTheRestAfterAPartialRefundAsksForWhatIsLeft(): void
    {
        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL, 'refunded_amount' => 2500])
            ->refund($this->order());

        self::assertStringContainsString('/order/refund', end($recorded)['url']);
    }

    public function testRefundingOnceNothingIsLeftIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('exceed the captured amount');

        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL, 'refunded_amount' => self::TOTAL])
            ->refund($this->order(), 100);
    }

    /**
     * A refund that matches whole lines sends those lines.
     *
     * Briqpay accepts an `adjustment` line for an arbitrary refund amount --
     * and only on a refund; a capture rejects it outright. But reaching for it
     * throws away what the line items carry: a PSP that settles per line can
     * only keep the buyer's invoice right if it is told which lines came back.
     * So the real lines are preferred whenever the amount allows.
     */
    public function testAPartialRefundPrefersTheRealLinesOverAnAdjustment(): void
    {
        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL], $this->twoLineSession())
            ->refund($this->order(), 2500);

        $cart = json_decode(end($recorded)['body'], true)['data']['order']['cart'];

        self::assertCount(1, $cart);
        self::assertSame('BQ-TEST-B', $cart[0]['reference']);
        self::assertNotSame('adjustment', $cart[0]['productType']);
    }

    /**
     * And falls back to one when they do not.
     *
     * Unlike a capture, a refund of an amount that matches no combination of
     * lines still has to be possible -- a goodwill sum, a part of a line -- and
     * `adjustment` is what the API offers for it.
     */
    public function testARefundThatMatchesNoLineFallsBackToAnAdjustment(): void
    {
        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL], $this->twoLineSession())
            ->refund($this->order(), 1337);

        $cart = json_decode(end($recorded)['body'], true)['data']['order']['cart'];

        self::assertSame('adjustment', $cart[0]['productType']);
    }

    /**
     * A capture has no such fallback: adjustment is refund-only.
     */
    public function testACaptureThatMatchesNoLineIsRefusedRatherThanAdjusted(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('whole order lines');

        $recorded = [];
        $this->manager($recorded, [], $this->twoLineSession())->capture($this->order(), 1337);
    }

    /**
     * A refund larger than any single capture's remaining balance has to be
     * split across captures. Confirmed live against the playground: a refund
     * request that exceeded both captures' individual remainders came back as
     * two distinct refund entries, each carrying the correct captureId and
     * summing exactly to the requested amount.
     *
     * The fullest capture is drawn from first, so a refund that happens to fit
     * inside one capture never gets split unnecessarily -- every split is
     * another refund on the order and another line on the buyer's statement.
     */
    public function testARefundLargerThanAnySingleCaptureIsSplitAcrossCaptures(): void
    {
        $recorded = [];
        $session = $this->twoCaptureSession(
            ['id' => 'cap-a', 'amount' => 5000, 'refunded' => 0],
            ['id' => 'cap-b', 'amount' => 3000, 'refunded' => 0]
        );

        $manager = $this->managerForSplit($recorded, $session, [
            'id_order' => 1, 'session_id' => 'sess-1', 'status' => 'approved',
            'captured_amount' => 8000, 'refunded_amount' => 0,
        ]);

        $manager->refund($this->order(), 7000);

        $posts = array_values(array_filter($recorded, static function ($call) {
            return strpos($call['url'], '/order/refund') !== false;
        }));

        self::assertCount(2, $posts, 'a split refund must produce two distinct API calls');

        $bodies = array_map(static function ($call) {
            return json_decode($call['body'], true);
        }, $posts);

        // The fuller capture (cap-a, 5000) is drawn from first and takes its
        // whole remaining balance; the rest (2000) is drawn from cap-b.
        self::assertSame('cap-a', $bodies[0]['captureId']);
        self::assertSame(5000, $bodies[0]['data']['order']['amountIncVat']);
        self::assertSame('cap-b', $bodies[1]['captureId']);
        self::assertSame(2000, $bodies[1]['data']['order']['amountIncVat']);

        $total = $bodies[0]['data']['order']['amountIncVat'] + $bodies[1]['data']['order']['amountIncVat'];
        self::assertSame(7000, $total, 'the split amounts must sum to exactly what was requested');
    }

    /**
     * A refund that fits inside one capture is never split, even when several
     * captures exist on the order.
     */
    public function testARefundThatFitsOneCaptureIsNotSplitEvenWithSeveralCapturesPresent(): void
    {
        $recorded = [];
        $session = $this->twoCaptureSession(
            ['id' => 'cap-a', 'amount' => 5000, 'refunded' => 0],
            ['id' => 'cap-b', 'amount' => 3000, 'refunded' => 0]
        );

        $manager = $this->managerForSplit($recorded, $session, [
            'id_order' => 1, 'session_id' => 'sess-1', 'status' => 'approved',
            'captured_amount' => 8000, 'refunded_amount' => 0,
        ]);

        $manager->refund($this->order(), 2500);

        $posts = array_values(array_filter($recorded, static function ($call) {
            return strpos($call['url'], '/order/refund') !== false;
        }));

        self::assertCount(1, $posts, 'an amount that one capture can cover must not be split');

        $body = json_decode($posts[0]['body'], true);
        self::assertSame('cap-a', $body['captureId'], 'drawn from the fuller capture, which alone can cover it');
    }

    /**
     * A refund that exceeds everything refundable across every capture is
     * refused outright, before any API call is made.
     */
    public function testARefundExceedingEveryCaptureCombinedIsRefused(): void
    {
        $this->expectException(ApiException::class);

        $recorded = [];
        $session = $this->twoCaptureSession(
            ['id' => 'cap-a', 'amount' => 5000, 'refunded' => 0],
            ['id' => 'cap-b', 'amount' => 3000, 'refunded' => 0]
        );

        $manager = $this->managerForSplit($recorded, $session, [
            'id_order' => 1, 'session_id' => 'sess-1', 'status' => 'approved',
            'captured_amount' => 8000, 'refunded_amount' => 0,
        ]);

        // 8001 exceeds the 8000 total ever captured, so the amount guard
        // refuses it before the capture ledger is even consulted.
        $manager->refund($this->order(), 8001);
    }

    /**
     * @param array $capA
     * @param array $capB
     *
     * @return array<string, mixed>
     */
    private function twoCaptureSession(array $capA, array $capB): array
    {
        return [
            'data' => [
                'order' => ['amountIncVat' => 8000, 'currency' => 'SEK', 'cart' => [
                    ['productType' => 'physical', 'reference' => 'x', 'quantity' => 1, 'unitPrice' => 6400, 'taxRate' => 2500],
                ]],
                'captures' => [
                    ['captureId' => $capA['id'], 'amountIncVat' => $capA['amount'], 'status' => 'approved', 'cart' => []],
                    ['captureId' => $capB['id'], 'amountIncVat' => $capB['amount'], 'status' => 'approved', 'cart' => []],
                ],
                'refunds' => [],
            ],
        ];
    }

    /**
     * A manager wired so a split refund's per-allocation order lookup
     * (orderIdFor -> findBySessionId) resolves correctly, matching what
     * execute() actually reads: the initial record, one findBySessionId per
     * split allocation, and the state-resolution read at the end.
     *
     * @param array<int, array>    $recorded
     * @param array<string, mixed> $session
     * @param array<string, mixed> $record
     */
    private function managerForSplit(array &$recorded, array $session, array $record): OrderManager
    {
        \Db::getInstance()->rows[] = $record; // requireRecord()
        \Db::getInstance()->rows[] = $record; // orderIdFor(), 1st allocation
        \Db::getInstance()->rows[] = $record; // orderIdFor(), 2nd allocation (harmless if unused)
        \Db::getInstance()->rows[] = $record; // resolveTargetState()

        $client = new Client('https://api.test', 'm', 's', 't', $this->makeTransport([
            ['status' => 200, 'body' => json_encode($session), 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded));

        return new OrderManager($client);
    }

    /* -----------------------------------------------------------------
     * Cancel
     * -------------------------------------------------------------- */

    public function testCancellingAnUncapturedAuthorisationIsAllowed(): void
    {
        $recorded = [];
        $this->manager($recorded)->cancel($this->order());

        self::assertStringContainsString('/order/cancel', end($recorded)['url']);
    }

    public function testCancellingAfterACaptureIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('can only be refunded, not cancelled');

        $recorded = [];
        $this->manager($recorded, ['captured_amount' => self::TOTAL])->cancel($this->order());
    }

    public function testCancellingRecordsTheStatusSoTheOrderStopsOfferingOperations(): void
    {
        $recorded = [];
        $this->manager($recorded)->cancel($this->order());

        $updates = array_filter(\Db::getInstance()->calls, static function ($call) {
            return $call['method'] === 'update';
        });

        $wrote = false;
        foreach ($updates as $call) {
            if (isset($call['args'][1]['status']) && $call['args'][1]['status'] === 'cancelled') {
                $wrote = true;
            }
        }

        self::assertTrue($wrote, 'the record should be marked cancelled');
    }

    /* -----------------------------------------------------------------
     * Operations on a payment that is not open to them
     * -------------------------------------------------------------- */

    /**
     * @dataProvider closedStatusProvider
     */
    public function testCaptureAndCancelAreRefusedOnAClosedPayment(string $status): void
    {
        foreach (['capture', 'cancel'] as $operation) {
            $recorded = [];
            $manager = $this->manager($recorded, ['status' => $status]);

            try {
                $manager->{$operation}($this->order());
                self::fail(sprintf('%s should be refused on a %s payment', $operation, $status));
            } catch (ApiException $e) {
                self::assertStringContainsString('cannot be', $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function closedStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'rejected' => ['rejected'],
            'cancelled' => ['cancelled'],
        ];
    }

    /**
     * An order PrestaShop knows about but Briqpay does not cannot be operated
     * on at all, and the message has to say which order.
     */
    public function testAnOrderWithNoPaymentRecordIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('No Briqpay payment is recorded');

        $recorded = [];
        $client = new Client('https://api.test', 'm', 's', 't', $this->makeTransport([], $recorded));

        (new OrderManager($client))->capture($this->order());
    }

    /**
     * @param array<int, array>    $recorded
     * @param array<string, mixed> $overrides
     */
    private function manager(array &$recorded, array $overrides = [], ?string $sessionBody = null): OrderManager
    {
        $row = array_merge([
            'id_order' => 1,
            'id_cart' => 1,
            'session_id' => 'sess-1',
            'status' => 'approved',
            'captured_amount' => 0,
            'refunded_amount' => 0,
        ], $overrides);

        // Read to find the session and check the operation, then again to total
        // the counters afterwards.
        \Db::getInstance()->rows[] = $row;
        \Db::getInstance()->rows[] = $row;

        $session = json_encode([
            'data' => [
                'order' => [
                    'amountIncVat' => self::TOTAL,
                    'currency' => 'SEK',
                    'cart' => [
                        [
                            'productType' => 'physical',
                            'reference' => 'BQ-TEST-STD',
                            'quantity' => 1,
                            'unitPrice' => 10000,
                            'taxRate' => 2500,
                        ],
                    ],
                ],
                'captures' => [],
                'refunds' => [],
            ],
        ]);

        $client = new Client('https://api.test', 'm', 's', 't', $this->makeTransport([
            ['status' => 200, 'body' => $sessionBody ?? $session, 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded));

        return new OrderManager($client);
    }

    private function order(): \Order
    {
        $order = new \Order(1);
        $order->total_paid_tax_incl = self::TOTAL / 100;
        $order->total_paid_tax_excl = 10000 / 100;
        $order->current_state = 2;

        return $order;
    }
}
