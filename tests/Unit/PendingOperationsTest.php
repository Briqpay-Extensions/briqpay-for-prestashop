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
use Briqpay\Payment\Order\StatusMapper;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * Capture and cancel act on an authorisation, and a pending payment does not
 * have one yet: the shopper may still be finishing a 3-D Secure step, and
 * Briqpay can still reject it. Offering those operations invites one that fails
 * at the PSP, or races the approval.
 *
 * @covers \Briqpay\Payment\Order\OrderManager
 * @covers \Briqpay\Payment\Order\StatusMapper::isPending
 */
class PendingOperationsTest extends TestCase
{
    public function testCaptureIsRefusedWhileThePaymentIsPending(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('cannot be captured yet');

        $recorded = [];
        $this->manager($recorded, StatusMapper::STATUS_PENDING)->capture($this->order());
    }

    public function testCancelIsRefusedWhileThePaymentIsPending(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('cannot be cancelled yet');

        $recorded = [];
        $this->manager($recorded, StatusMapper::STATUS_PENDING)->cancel($this->order());
    }

    /**
     * A rejected payment has nothing to act on either, and unlike pending it is
     * never going to.
     */
    public function testCaptureIsRefusedOnARejectedPayment(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('cannot be captured yet');

        $recorded = [];
        $this->manager($recorded, StatusMapper::STATUS_REJECTED)->capture($this->order());
    }

    public function testCaptureIsAllowedOnceApproved(): void
    {
        $recorded = [];
        $manager = $this->manager($recorded, StatusMapper::STATUS_APPROVED);

        $manager->capture($this->order());

        self::assertStringContainsString('/order/capture', end($recorded)['url']);
    }

    public function testTheOperationsPredicateFollowsTheSameRule(): void
    {
        self::assertTrue(OrderManager::allowsAuthorisationOperations(['status' => 'approved']));

        foreach (['pending', 'rejected', 'cancelled', ''] as $status) {
            self::assertFalse(
                OrderManager::allowsAuthorisationOperations(['status' => $status]),
                $status . ' should not allow capture or cancel'
            );
        }
    }

    public function testAMissingStatusIsNotTreatedAsApproved(): void
    {
        self::assertFalse(OrderManager::allowsAuthorisationOperations([]));
    }

    public function testPendingIsDistinctFromTheTerminalStatuses(): void
    {
        self::assertTrue(StatusMapper::isPending('pending'));

        foreach (['approved', 'rejected', 'cancelled'] as $status) {
            self::assertFalse(StatusMapper::isPending($status));
        }
    }

    /**
     * A refund is gated on money actually captured, not on the session status,
     * and must stay possible after the session has moved on.
     */
    public function testRefundIsNotGatedOnTheSessionStatus(): void
    {
        $recorded = [];
        $manager = $this->manager($recorded, 'cancelled', ['captured_amount' => 4465]);

        $manager->refund($this->order());

        self::assertStringContainsString('/order/refund', end($recorded)['url']);
    }

    /**
     * A capture Briqpay has accepted but not settled counts for nothing in
     * captured_amount -- the money has not moved -- so the amount guard sees an
     * order with nothing captured and would wave a second capture through.
     */
    public function testASecondCaptureIsRefusedWhileOneIsStillSettling(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('already in progress');

        $this->managerWithSession(
            '{"data":{"captures":[{"status":"pending","amountIncVat":4465}]}}'
        )->capture($this->order());
    }

    public function testASecondRefundIsRefusedWhileOneIsStillSettling(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('already in progress');

        $this->managerWithSession(
            '{"data":{"refunds":[{"status":"pending","amountIncVat":1000}]}}',
            ['captured_amount' => 4465]
        )->refund($this->order(), 1000);
    }

    /**
     * A settled capture is not in flight: it is simply captured, and the amount
     * guard decides from there.
     */
    public function testAnApprovedCaptureDoesNotCountAsInFlight(): void
    {
        $recorded = [];
        $manager = $this->managerWithSession(
            '{"data":{"captures":[{"status":"approved","amountIncVat":1000}]}}',
            ['captured_amount' => 1000],
            $recorded
        );

        $manager->capture($this->order(), 1000);

        self::assertStringContainsString('/order/capture', end($recorded)['url']);
    }

    /**
     * An outage must not make capture impossible; the amount guard still holds.
     */
    public function testAFailedInFlightCheckDoesNotBlockTheOperation(): void
    {
        $recorded = [];
        $row = [
            'id_order' => 1, 'id_cart' => 1, 'session_id' => 'sess-1',
            'status' => 'approved', 'captured_amount' => 0, 'refunded_amount' => 0,
        ];
        \Db::getInstance()->rows[] = $row;
        \Db::getInstance()->rows[] = $row;

        $client = new Client('https://api.test', 'm', 's', 't', $this->makeTransport([
            ['status' => 401, 'body' => '{}', 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded));

        (new OrderManager($client))->capture($this->order());

        self::assertStringContainsString('/order/capture', end($recorded)['url']);
    }

    /**
     * Cancel releases the authorisation a pending capture is drawing on, so the
     * two must not race.
     */
    public function testCancelIsRefusedWhileACaptureIsSettling(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('capture of 44.65 is already in progress');

        $this->managerWithSession(
            '{"data":{"captures":[{"status":"pending","amountIncVat":4465}]}}'
        )->cancel($this->order());
    }

    /**
     * Briqpay matches every capture line against the session by reference and
     * tax rate. Rebuilding them from the PrestaShop order reproduces neither
     * reliably -- a discount is named after the voucher in the session but only
     * aggregated in the order, and a cart mixing VAT rates gives that aggregate
     * a blended rate recomputed from different numbers on each side. The
     * capture is then rejected with CART_ITEM_NOT_FOUND.
     */
    public function testAFullCaptureEchoesTheSessionCartRatherThanRebuildingIt(): void
    {
        // The session total matches the order it was opened for, as it always
        // does in practice.
        $sessionCart = '{"data":{"order":{"amountIncVat":4465,"currency":"SEK","cart":['
            . '{"productType":"physical","reference":"demo_1","quantity":1,"unitPrice":1912,"taxRate":2500},'
            . '{"productType":"discount","reference":"BRIQPAY10","quantity":1,"unitPrice":-491,"taxRate":973}'
            . ']},"captures":[]}}';

        $recorded = [];
        $this->managerWithSession($sessionCart, [], $recorded)->capture($this->order());

        $sent = json_decode(end($recorded)['body'], true);

        self::assertSame(4465, $sent['data']['order']['amountIncVat']);
        self::assertSame('BRIQPAY10', $sent['data']['order']['cart'][1]['reference']);
        self::assertSame(973, $sent['data']['order']['cart'][1]['taxRate']);
    }

    /**
     * A partial capture is a subset of the session's own lines.
     *
     * Briqpay matches every capture line against the session and rejects a
     * reference it does not know, so a line invented for an arbitrary amount is
     * refused outright -- CART_ITEM_NOT_FOUND for a valid productType, or
     * INVALID_DATA for the "adjustment" type the module used to send, which is
     * not a product type the API has at all.
     */
    public function testAPartialCaptureSendsWholeSessionLines(): void
    {
        $recorded = [];
        $this->managerWithSession($this->twoLineSession(), [], $recorded)
            ->capture($this->order(), 2390);

        $sent = json_decode(end($recorded)['body'], true);
        $cart = $sent['data']['order']['cart'];

        self::assertCount(1, $cart);
        self::assertSame('demo_1', $cart[0]['reference']);
        self::assertSame(2390, $sent['data']['order']['amountIncVat']);
        self::assertSame(1912, $sent['data']['order']['amountExVat']);
    }

    public function testSeveralLinesAreCombinedToReachTheAmount(): void
    {
        $recorded = [];
        $this->managerWithSession($this->twoLineSession(), [], $recorded)
            ->capture($this->order(), 3265);

        $cart = json_decode(end($recorded)['body'], true)['data']['order']['cart'];

        self::assertCount(2, $cart);
    }

    /**
     * An amount that matches no combination of lines has to be refused here,
     * with something a merchant can act on -- rather than sent for Briqpay to
     * reject as CART_ITEM_NOT_FOUND long after the order was placed.
     */
    public function testAnAmountThatMatchesNoLineIsRefusedWithAnExplanation(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('whole order lines');

        $recorded = [];
        $this->managerWithSession($this->twoLineSession(), [], $recorded)
            ->capture($this->order(), 1000);
    }

    /** A session carrying a product and a shipping line. */
    private function twoLineSession(): string
    {
        return '{"data":{"order":{"amountIncVat":3265,"currency":"SEK","cart":['
            . '{"productType":"physical","reference":"demo_1","quantity":1,"unitPrice":1912,"taxRate":2500},'
            . '{"productType":"shipping_fee","reference":"2","quantity":1,"unitPrice":700,"taxRate":2500}'
            . ']},"captures":[]}}';
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<int, array>    $recorded
     */
    private function managerWithSession(
        string $sessionBody,
        array $extra = [],
        array &$recorded = []
    ): OrderManager {
        $row = array_merge([
            'id_order' => 1,
            'id_cart' => 1,
            'session_id' => 'sess-1',
            'status' => 'approved',
            'captured_amount' => 0,
            'refunded_amount' => 0,
        ], $extra);

        \Db::getInstance()->rows[] = $row;
        \Db::getInstance()->rows[] = $row;

        $client = new Client('https://api.test', 'm', 's', 't', $this->makeTransport([
            ['status' => 200, 'body' => $sessionBody, 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded));

        return new OrderManager($client);
    }

    /**
     * @param array<int, array>    $recorded
     * @param array<string, mixed> $extra
     */
    private function manager(array &$recorded = [], string $status = 'pending', array $extra = []): OrderManager
    {
        $row = array_merge([
            'id_order' => 1,
            'id_cart' => 1,
            'session_id' => 'sess-1',
            'status' => $status,
            'captured_amount' => 0,
            'refunded_amount' => 0,
        ], $extra);

        // Read once to find the session, and again to total the counters.
        \Db::getInstance()->rows[] = $row;
        \Db::getInstance()->rows[] = $row;

        $client = new Client(
            'https://api.test',
            'merchant',
            'secret',
            'test',
            // The in-flight check reads the session first, then the operation.
            $this->makeTransport([
                ['status' => 200, 'body' => '{"data":{"captures":[],"refunds":[]}}', 'error' => null],
                ['status' => 200, 'body' => '{}', 'error' => null],
            ], $recorded)
        );

        return new OrderManager($client);
    }

    private function order(): \Order
    {
        $order = new \Order(1);
        $order->total_paid_tax_incl = 44.65;
        $order->total_paid_tax_excl = 35.72;
        $order->current_state = 2;

        return $order;
    }
}
