<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Order;

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Builder\Money;
use Briqpay\Payment\Builder\OrderLineBuilder;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Support\Lock;
use Briqpay\Payment\Support\Logger;
use Briqpay\Payment\Webhook\WebhookHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Post-purchase money movement: capture, refund and cancel.
 *
 * The previous module could only capture and refund, drove both from the live
 * cart, and registered the state-change hook under a name PrestaShop never
 * calls -- so neither actually ran.
 */
class OrderManager
{
    /** @var Client */
    private $client;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var OrderLineBuilder */
    private $lineBuilder;

    /** @var StateApplier */
    private $stateApplier;

    public function __construct(
        Client $client,
        ?OrderRepository $orderRepository = null,
        ?OrderLineBuilder $lineBuilder = null,
        ?StateApplier $stateApplier = null
    ) {
        $this->client = $client;
        $this->orderRepository = $orderRepository !== null ? $orderRepository : new OrderRepository();
        $this->lineBuilder = $lineBuilder !== null ? $lineBuilder : new OrderLineBuilder();
        $this->stateApplier = $stateApplier !== null ? $stateApplier : new StateApplier();
    }

    /**
     * Capture funds for an order.
     *
     * @param \Order   $order
     * @param int|null $amountIncVat Minor units; null captures the full order.
     *
     * @return array
     *
     * @throws ApiException
     */
    public function capture(\Order $order, $amountIncVat = null)
    {
        return $this->execute($order, 'capture', $amountIncVat, 'captured_amount');
    }

    /**
     * Refund funds for an order.
     *
     * @param \Order   $order
     * @param int|null $amountIncVat Minor units; null refunds the full order.
     *
     * @return array
     *
     * @throws ApiException
     */
    public function refund(\Order $order, $amountIncVat = null)
    {
        return $this->execute($order, 'refund', $amountIncVat, 'refunded_amount');
    }

    /**
     * Refund exactly these lines.
     *
     * Used where the shop already knows which products are coming back -- a
     * credit slip, or the line picker on the order page -- so the PSP is told
     * what was returned rather than only how much.
     *
     * @param \Order $order
     * @param array  $lines        Briqpay cart lines.
     * @param int    $amountIncVat Minor units the lines are worth.
     *
     * @return array
     *
     * @throws ApiException
     */
    public function refundLines(\Order $order, array $lines, $amountIncVat)
    {
        return $this->execute($order, 'refund', (int) $amountIncVat, 'refunded_amount', $lines);
    }

    /**
     * Release an authorisation that has not been captured.
     *
     * @param \Order $order
     *
     * @return array
     *
     * @throws ApiException
     */
    public function cancel(\Order $order)
    {
        return $this->execute($order, 'cancel', null, null);
    }

    /**
     * @param \Order      $order
     * @param string      $operation     capture|refund|cancel
     * @param int|null    $amountIncVat
     * @param string|null $counterColumn
     *
     * @return array
     *
     * @throws ApiException
     */
    private function execute(\Order $order, $operation, $amountIncVat, $counterColumn, array $lines = [])
    {
        $record = $this->requireRecord($order);
        $sessionId = (string) $record['session_id'];

        $lock = Lock::forOrder((int) $order->id);
        if (!$lock->acquire(10)) {
            throw new ApiException(sprintf(
                'Another %s is already running for order %s.',
                $operation,
                $order->reference
            ));
        }

        try {
            $this->assertOperationAllowed($operation, $record, $order, $amountIncVat);

            $session = $this->readSession($sessionId);
            $this->assertNothingInFlight($operation, $session);

            // Resolve "everything" to a figure once, so the payload asks for
            // exactly what the guard just approved.
            $effective = $amountIncVat === null && $counterColumn !== null
                ? self::remainingFor($operation, $record, $order)
                : $amountIncVat;

            // "Capture everything left" is bounded by what Briqpay's own order
            // lines sum to, not by PrestaShop's order total -- and the two can
            // disagree by a minor unit. Each line's incVat is rounded on its
            // own, so three lines at 19% can sum to one cent more than the
            // order total, which is rounded once on the aggregate ("sum of
            // roundings" against "rounding of a sum"). Invisible while nothing
            // has been partially captured yet -- a first whole-order capture
            // echoes the session cart verbatim and never has to match a
            // subset of lines to a target -- but a capture of the remainder
            // after a partial one does, and a target that is one cent short
            // of the real remaining lines matches no combination of them at
            // all: Briqpay refuses with CART_ITEM_NOT_FOUND-adjacent errors
            // for an amount that is, in truth, entirely legitimate.
            //
            // Widening to the larger of the two figures costs nothing when
            // they already agree, and is exactly what lets the true remaining
            // lines be found when they do not.
            if ($operation === 'capture' && $amountIncVat === null) {
                $effective = max($effective, self::remainingByLines($session, $record));
            }

            $payload = $this->buildPayload($order, $operation, $effective, $session, $amountIncVat === null, $lines);

            \Hook::exec('actionBriqpayBeforePaymentOperation', [
                'operation' => $operation,
                'order' => $order,
                'payload' => &$payload,
            ]);

            Logger::info('Executing payment operation.', [
                'operation' => $operation,
                'orderId' => (int) $order->id,
                'sessionId' => $sessionId,
                'amount' => $amountIncVat,
            ]);

            if ($operation === 'refund') {
                $response = $this->refundAgainstCaptures($sessionId, $session, $payload, (int) $effective);
            } else {
                $response = $this->client->post(
                    '/v3/session/' . rawurlencode($sessionId) . '/order/' . $operation,
                    $payload
                );
            }

            if ($counterColumn !== null) {
                // Reuse $effective rather than recomputing: it is what was
                // actually sent to Briqpay, widened where a whole capture
                // needed it, and the counter has to agree with that or the
                // stored amount silently drifts from what Briqpay holds until
                // the next sync corrects it.
                $applied = $amountIncVat !== null ? (int) $amountIncVat : $effective;

                $this->orderRepository->incrementAmount((int) $order->id, $counterColumn, $applied);
            }

            if ($operation === 'cancel') {
                $this->orderRepository->updateByOrderId((int) $order->id, ['status' => 'cancelled']);
            }

            \Hook::exec('actionBriqpayAfterPaymentOperation', [
                'operation' => $operation,
                'order' => $order,
                'response' => $response,
            ]);

            $targetState = $this->resolveTargetState($operation, $order);
        } finally {
            $lock->release();
        }

        // Deliberately outside the lock: applying the state takes the same
        // order lock, and Briqpay has already moved the money by this point, so
        // nothing here may fail the operation.
        //
        // Briqpay reports the same event on a webhook moments later -- a refund
        // has been seen to take ninety seconds -- and until it lands the order
        // still reads Payment accepted, leaving a merchant who has just
        // refunded unable to tell whether it worked. Whichever arrives first
        // moves the order; the other finds it already there.
        $this->stateApplier->apply($order, $targetState, $sessionId);

        return $response;
    }

    /**
     * Where an order belongs once an operation has succeeded.
     *
     * Reads the counters back rather than trusting the amount just sent: a
     * second partial refund is only a full one when added to the first.
     *
     * @param string $operation
     * @param \Order $order
     *
     * @return int Zero when the state should not move.
     */
    private function resolveTargetState($operation, \Order $order)
    {
        if ($operation === 'cancel') {
            return StateApplier::stateAfterCancel();
        }

        if ($operation === 'capture') {
            return StateApplier::stateAfterCapture();
        }

        $record = $this->orderRepository->findByOrderId((int) $order->id);

        if ($record === null) {
            return 0;
        }

        return StateApplier::stateAfterRefund(
            isset($record['refunded_amount']) ? (int) $record['refunded_amount'] : 0,
            isset($record['captured_amount']) ? (int) $record['captured_amount'] : 0
        );
    }

    /**
     * @param \Order   $order
     * @param string   $operation
     * @param int|null $amountIncVat
     *
     * @return array
     */
    private function buildPayload(\Order $order, $operation, $amountIncVat, array $session = [], $whole = false, array $lines = [])
    {
        if ($operation === 'cancel') {
            return [];
        }

        // Lines the caller already resolved win over anything derived here.
        if ($lines !== []) {
            return ['data' => ['order' => self::orderFromLines($lines, $session, (int) $amountIncVat)]];
        }

        // For a whole-order operation, send back exactly the cart the session
        // already carries.
        //
        // Briqpay matches every line against the session by reference and tax
        // rate, and rebuilding them from the PrestaShop order reproduces
        // neither reliably. A discount is the clearest case: the session names
        // it after the voucher ("BRIQPAY10") while the order knows only an
        // aggregate, and a cart mixing VAT rates gives that aggregate a blended
        // rate that is recomputed from different numbers on each side -- 9.73%
        // at checkout against 9.78% at capture. The capture is then rejected
        // with CART_ITEM_NOT_FOUND, and no amount of rounding care fixes it,
        // because the two sides are deriving the figure from different inputs.
        //
        // Echoing the session's own lines cannot drift by construction.
        $sessionOrder = isset($session['data']['order']) && is_array($session['data']['order'])
            ? $session['data']['order']
            : null;

        // Only when the operation covers the whole cart. "Capture the rest"
        // after a partial capture is a whole-order request from the merchant's
        // side, but sending the whole cart would ask for the part already
        // captured a second time.
        $coversEverything = $whole
            && $sessionOrder !== null
            && (int) $amountIncVat === (int) $sessionOrder['amountIncVat'];

        if ($coversEverything) {
            return ['data' => ['order' => $sessionOrder]];
        }

        // Prefer the real lines, for both operations.
        //
        // Briqpay's cart accepts an `adjustment` line for an arbitrary amount,
        // but only on a refund, and reaching for it throws away what the line
        // items carry: a PSP that settles per line -- Klarna and friends -- can
        // only keep the buyer's invoice correct if it is told which lines came
        // back. An adjustment collapses that into a number and the PSP loses
        // the advantage it was chosen for.
        //
        // So the lines are matched first, whatever the operation.
        $lines = $sessionOrder !== null && isset($sessionOrder['cart'])
            ? $this->selectLines($sessionOrder, (int) $amountIncVat)
            : null;

        if ($lines !== null) {
            return ['data' => ['order' => $lines]];
        }

        // A capture has no fallback: `adjustment` is refund-only, and any line
        // the session does not know is rejected as CART_ITEM_NOT_FOUND. Better
        // to say so here than to have Briqpay refuse it after the fact.
        //
        // Only when there are lines to match against, though. An unreadable
        // session -- an outage mid-operation -- must not make capture
        // impossible; the order's own lines are the best available guess then,
        // and Briqpay is still the one that decides.
        if ($operation === 'capture' && $sessionOrder !== null && isset($sessionOrder['cart'])) {
            throw new ApiException(sprintf(
                'Briqpay captures whole order lines, and %s does not match any combination of them. '
                . 'Capture the full amount, or an amount that adds up from the order lines.',
                self::formatAmount((int) $amountIncVat)
            ));
        }

        $label = $operation === 'refund' ? 'Partial refund' : 'Partial capture';

        $orderPayload = $amountIncVat === null
            ? $this->lineBuilder->buildOrder($order)
            : $this->lineBuilder->buildPartial($order, $amountIncVat, $label);

        return ['data' => ['order' => $orderPayload]];
    }

    /**
     * Send a refund against the capture, or captures, that can cover it.
     *
     * A Briqpay refund belongs to a capture rather than to the order. With one
     * capture the endpoint infers it; with several, a refund naming none is
     * rejected with HTTP 400, and a refund cannot draw more than its parent
     * capture holds. So an amount larger than any single remaining capture has
     * to become several refunds.
     *
     * @param string $sessionId
     * @param array  $session
     * @param array  $payload
     * @param int    $amountIncVat
     *
     * @return array The last response, or the only one.
     *
     * @throws ApiException
     */
    private function refundAgainstCaptures($sessionId, array $session, array $payload, $amountIncVat)
    {
        $url = '/v3/session/' . rawurlencode($sessionId) . '/order/refund';
        $ledger = new CaptureLedger($session);

        if (!$ledger->needsCaptureId()) {
            return $this->client->post($url, $payload);
        }

        // Lines belong to the capture that captured them, so prefer the capture
        // holding all of them: that keeps the refund line-level, which is the
        // whole point of sending lines at all.
        $lines = isset($payload['data']['order']['cart']) ? $payload['data']['order']['cart'] : [];
        $holder = $lines === [] ? '' : $ledger->captureHolding($lines);

        if ($holder !== '') {
            return $this->client->post($url, array_merge(['captureId' => $holder], $payload));
        }

        $allocations = $ledger->allocate($amountIncVat);

        if ($allocations === []) {
            throw new ApiException(sprintf(
                'No capture on this order can cover a refund of %s. '
                . 'At most %s is still refundable.',
                self::formatAmount($amountIncVat),
                self::formatAmount($ledger->refundable())
            ));
        }

        $response = [];

        foreach ($allocations as $allocation) {
            // Split refunds cannot carry the original lines: each draws a part
            // of the amount from a different capture, and the lines belong to
            // one of them. An adjustment is what the API offers for that.
            $body = count($allocations) === 1
                ? $payload
                : ['data' => ['order' => $this->lineBuilder->buildPartial(
                    new \Order((int) $this->orderIdFor($sessionId)),
                    $allocation['amount'],
                    'Partial refund'
                )]];

            $response = $this->client->post($url, array_merge(['captureId' => $allocation['id']], $body));
        }

        return $response;
    }

    /**
     * @param string $sessionId
     *
     * @return int
     */
    private function orderIdFor($sessionId)
    {
        $record = $this->orderRepository->findBySessionId((string) $sessionId);

        return $record === null ? 0 : (int) $record['id_order'];
    }

    /**
     * Wrap resolved lines in the order envelope Briqpay expects.
     *
     * @param array $lines
     * @param array $session
     * @param int   $amountIncVat
     *
     * @return array
     */
    private static function orderFromLines(array $lines, array $session, $amountIncVat)
    {
        $exVat = 0;

        foreach ($lines as $line) {
            $exVat += self::lineTotalExVat($line);
        }

        return [
            'amountIncVat' => $amountIncVat,
            'amountExVat' => $exVat,
            'currency' => isset($session['data']['order']['currency'])
                ? (string) $session['data']['order']['currency']
                : '',
            'cart' => array_values($lines),
        ];
    }

    /**
     * The session's own lines, as many as add up to the requested amount.
     *
     * @param array $order        The session's `data.order`.
     * @param int   $amountIncVat Minor units.
     *
     * @return array|null Null when no combination of whole lines matches.
     */
    private function selectLines(array $order, $amountIncVat)
    {
        $selected = [];
        $runningIncVat = 0;
        $runningExVat = 0;

        foreach ($order['cart'] as $line) {
            $lineIncVat = self::lineTotalIncVat($line);
            $lineExVat = self::lineTotalExVat($line);

            if ($runningIncVat + $lineIncVat > $amountIncVat) {
                continue;
            }

            $selected[] = $line;
            $runningIncVat += $lineIncVat;
            $runningExVat += $lineExVat;
        }

        if ($runningIncVat !== $amountIncVat) {
            return null;
        }

        return [
            'amountIncVat' => $runningIncVat,
            'amountExVat' => $runningExVat,
            'currency' => isset($order['currency']) ? (string) $order['currency'] : '',
            'cart' => $selected,
        ];
    }

    /**
     * A cart line's total including VAT, in minor units.
     *
     * @param array $line
     *
     * @return int
     */
    private static function lineTotalIncVat(array $line)
    {
        // Briqpay returns the figure it computed; trust that over recomputing
        // it, so rounding cannot disagree with the session.
        if (isset($line['totalAmount'])) {
            return (int) $line['totalAmount'];
        }

        return (int) round(self::lineTotalExVat($line) * (1 + self::lineTaxRate($line) / 10000));
    }

    /**
     * A cart line's total excluding VAT, in minor units.
     *
     * Reads defensively: this runs while a capture is being assembled, and a
     * line missing a key it was not expected to miss must not take the whole
     * operation down with an undefined-index fatal.
     *
     * @param array $line
     *
     * @return int
     */
    private static function lineTotalExVat(array $line)
    {
        $unit = isset($line['unitPrice']) ? (int) $line['unitPrice'] : 0;
        $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;

        return $unit * $quantity;
    }

    /**
     * @param array $line
     *
     * @return int
     */
    private static function lineTaxRate(array $line)
    {
        return isset($line['taxRate']) ? (int) $line['taxRate'] : 0;
    }

    /**
     * Guard against operations that Briqpay would reject anyway, so the
     * merchant gets a clear message instead of a raw API error.
     *
     * @param string   $operation
     * @param array    $record
     * @param \Order   $order
     * @param int|null $amountIncVat
     *
     * @return void
     *
     * @throws ApiException
     */
    private function assertOperationAllowed($operation, array $record, \Order $order, $amountIncVat)
    {
        // Capture and cancel both act on an authorisation, and a pending
        // payment does not have one yet: the shopper may still be completing a
        // 3-D Secure step or waiting on their bank, and Briqpay can still
        // reject it. Acting now fails at the PSP at best; at worst it races the
        // approval. A rejected or cancelled payment has nothing to act on
        // either, so the rule covers both.
        //
        // Refund is deliberately not covered: it is gated on money actually
        // captured, which is the real precondition, and must stay possible even
        // if the session has since moved on.
        if (($operation === 'capture' || $operation === 'cancel')
            && !self::allowsAuthorisationOperations($record)
        ) {
            throw new ApiException(sprintf(
                'This payment is %s with Briqpay, so it cannot be %s yet. '
                . 'Capture and cancel become available once the payment is approved.',
                StatusMapper::normalise(isset($record['status']) ? (string) $record['status'] : ''),
                $operation === 'capture' ? 'captured' : 'cancelled'
            ));
        }

        $total = Money::toMinorUnits($order->total_paid_tax_incl);
        $captured = isset($record['captured_amount']) ? (int) $record['captured_amount'] : 0;
        $refunded = isset($record['refunded_amount']) ? (int) $record['refunded_amount'] : 0;

        // Cancel releases the whole authorisation; there is no amount to check,
        // and running the amount rules against it reports "the amount must be
        // greater than zero" for an operation nobody gave an amount to.
        if ($operation === 'cancel') {
            if ($captured > 0) {
                throw new ApiException(
                    'This order has already been captured and can only be refunded, not cancelled.'
                );
            }

            return;
        }

        // "The full amount" means whatever is still outstanding, not the order
        // total. After a partial refund of 25 on a 125 order, asking to refund
        // the rest has to mean 100 -- reading it as 125 makes the full-refund
        // button fail for good the moment anyone refunds part of an order.
        $requested = $amountIncVat === null
            ? self::remainingFor($operation, $record, $order)
            : (int) $amountIncVat;

        // The specific reasons first. A refund with nothing captured and a
        // capture with nothing left both leave a remainder of zero, and
        // reporting that as "the amount must be greater than zero" tells the
        // merchant nothing about what is actually wrong.
        if ($operation === 'refund' && $captured <= 0) {
            throw new ApiException('Nothing has been captured on this order yet, so there is nothing to refund.');
        }

        if ($amountIncVat === null && $requested <= 0) {
            throw new ApiException($operation === 'refund'
                ? 'This order is already fully refunded.'
                : 'This order is already fully captured.');
        }

        if ($requested <= 0) {
            throw new ApiException('The amount must be greater than zero.');
        }

        if ($operation === 'capture') {
            if ($captured + $requested > $total) {
                throw new ApiException(sprintf(
                    'Capturing %s would exceed the order total; %s of %s is already captured.',
                    self::formatAmount($requested),
                    self::formatAmount($captured),
                    self::formatAmount($total)
                ));
            }
        }

        if ($operation === 'refund') {
            if ($captured <= 0) {
                throw new ApiException('Nothing has been captured on this order yet, so there is nothing to refund.');
            }

            if ($refunded + $requested > $captured) {
                throw new ApiException(sprintf(
                    'Refunding %s would exceed the captured amount; %s of %s is already refunded.',
                    self::formatAmount($requested),
                    self::formatAmount($refunded),
                    self::formatAmount($captured)
                ));
            }
        }

    }

    /**
     * @param \Order $order
     *
     * @return array
     *
     * @throws ApiException
     */
    private function requireRecord(\Order $order)
    {
        $record = $this->orderRepository->findByOrderId((int) $order->id);

        if ($record === null || empty($record['session_id'])) {
            throw new ApiException(sprintf(
                'No Briqpay payment is recorded for order %s.',
                $order->reference
            ));
        }

        return $record;
    }

    /**
     * @param int $minorUnits
     *
     * @return string
     */
    private static function formatAmount($minorUnits)
    {
        return number_format(((int) $minorUnits) / 100, 2, '.', '');
    }

    /**
     * Refuse an operation while Briqpay is still settling one of the same kind.
     *
     * A capture that has been accepted but not settled counts for nothing in
     * the stored captured_amount -- correctly, the money has not moved -- so
     * the amount guard above sees an order with nothing captured and waves a
     * second capture straight through. Briqpay is the only place that knows a
     * capture is already under way, so it has to be asked.
     *
     * An empty session means the read failed, which does not block: an outage
     * should not make capture impossible, and the amount guard still applies.
     *
     * @param string $operation
     * @param array  $session   The live session, or empty if it could not be read.
     *
     * @return void
     *
     * @throws ApiException
     */
    private function assertNothingInFlight($operation, array $session)
    {
        if (empty($session)) {
            return;
        }

        // Cancel releases the authorisation a pending capture is drawing on, so
        // it has to wait for that capture too -- otherwise the two race, and
        // which one wins is Briqpay's business, not something the merchant can
        // see or undo.
        $key = $operation === 'refund' ? 'refunds' : 'captures';
        $pending = WebhookHandler::sumPendingAmounts($session, $key);

        if ($pending <= 0) {
            return;
        }

        $inFlight = $key === 'captures' ? 'capture' : 'refund';

        throw new ApiException(sprintf(
            'A %s of %s is already in progress on this order. '
            . 'Wait for Briqpay to confirm it before sending another.',
            $inFlight,
            self::formatAmount($pending)
        ));
    }

    /**
     * The live session, or an empty array if it cannot be read.
     *
     * A failed read does not block the operation: an outage should not make
     * capture impossible, and the amount guard still applies.
     *
     * @param string $sessionId
     *
     * @return array
     */
    private function readSession($sessionId)
    {
        try {
            return $this->client->get('/v3/session/' . rawurlencode($sessionId));
        } catch (ApiException $e) {
            Logger::info('Could not read the session before the operation; continuing.', [
                'sessionId' => $sessionId,
            ]);

            return [];
        }
    }

    /**
     * What an operation still has left to act on, in minor units.
     *
     * @param string $operation
     * @param array  $record
     * @param \Order $order
     *
     * @return int
     */
    private static function remainingFor($operation, array $record, \Order $order)
    {
        if ($operation === 'refund') {
            return self::getRefundableAmount($record);
        }

        return self::getCapturableAmount($record, $order);
    }

    /**
     * What is left to capture, reckoned from Briqpay's own order lines rather
     * than PrestaShop's order total.
     *
     * The two are usually the same figure. They can disagree by a minor unit
     * once VAT is a rate like 19% or 7%: each line's incVat is rounded on its
     * own, and summing several independently-rounded lines does not always
     * equal rounding their combined ex-VAT once, which is how PrestaShop
     * arrives at the order total. capturing "the rest" then has to match a
     * target against the real remaining lines, and a target that is a cent
     * short of what they actually sum to matches none of them.
     *
     * @param array $session A session as Briqpay returns it.
     * @param array $record
     *
     * @return int Minor units. Zero if the session carries no cart to read.
     */
    private static function remainingByLines(array $session, array $record)
    {
        $cart = isset($session['data']['order']['cart']) && is_array($session['data']['order']['cart'])
            ? $session['data']['order']['cart']
            : null;

        if ($cart === null) {
            return 0;
        }

        $total = 0;

        foreach ($cart as $line) {
            $total += self::lineTotalIncVat($line);
        }

        $captured = isset($record['captured_amount']) ? (int) $record['captured_amount'] : 0;

        return max(0, $total - $captured);
    }

    /**
     * Whether the payment is settled enough to capture or cancel.
     *
     * The order panel asks this too, so the buttons it offers are exactly the
     * operations this class will accept.
     *
     * @param array $record
     *
     * @return bool
     */
    public static function allowsAuthorisationOperations(array $record)
    {
        return StatusMapper::isApproved(isset($record['status']) ? (string) $record['status'] : '');
    }

    /**
     * Remaining amount that may still be captured, in minor units.
     *
     * @param array  $record
     * @param \Order $order
     *
     * @return int
     */
    public static function getCapturableAmount(array $record, \Order $order)
    {
        $total = Money::toMinorUnits($order->total_paid_tax_incl);
        $captured = isset($record['captured_amount']) ? (int) $record['captured_amount'] : 0;

        return max(0, $total - $captured);
    }

    /**
     * Remaining amount that may still be refunded, in minor units.
     *
     * @param array $record
     *
     * @return int
     */
    public static function getRefundableAmount(array $record)
    {
        $captured = isset($record['captured_amount']) ? (int) $record['captured_amount'] : 0;
        $refunded = isset($record['refunded_amount']) ? (int) $record['refunded_amount'] : 0;

        return max(0, $captured - $refunded);
    }
}
