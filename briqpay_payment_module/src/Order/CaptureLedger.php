<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What each capture on a session still has left to refund.
 *
 * A Briqpay refund belongs to a capture, not to the order: every refund carries
 * a parentCaptureId, and the endpoint takes a captureId to say which capture is
 * being refunded. With a single capture it can be left out and Briqpay infers
 * it -- which is why one-capture shops never notice -- but the moment an order
 * is captured in parts, a refund that names no capture is rejected outright
 * with HTTP 400.
 *
 * Nor is it enough to name any capture: a refund cannot exceed the capture it
 * is drawn against, so refunding 80 of an order captured as 50 and 30 has to be
 * split into two refunds. This works out which capture, or captures, a refund
 * should be drawn from.
 */
class CaptureLedger
{
    /** @var array<int, array{id:string, amount:int, refunded:int, remaining:int, cart:array}> */
    private $captures;

    /**
     * @param array $session A session as Briqpay returns it.
     */
    public function __construct(array $session)
    {
        $this->captures = self::read($session);
    }

    /**
     * @return array<int, array{id:string, amount:int, refunded:int, remaining:int, cart:array}>
     */
    public function captures()
    {
        return $this->captures;
    }

    /**
     * Whether a captureId has to be sent at all.
     *
     * With one capture Briqpay infers it, and older sessions created before any
     * of this existed keep working untouched.
     *
     * @return bool
     */
    public function needsCaptureId()
    {
        return count($this->captures) > 1;
    }

    /**
     * Total still refundable across every capture.
     *
     * @return int Minor units.
     */
    public function refundable()
    {
        $total = 0;

        foreach ($this->captures as $capture) {
            $total += $capture['remaining'];
        }

        return $total;
    }

    /**
     * How to draw an amount from the captures that can cover it.
     *
     * Fullest capture first, so a refund that fits inside one is sent as one
     * refund rather than split needlessly -- every split is another refund the
     * merchant sees on the order and another row on the buyer's statement.
     *
     * @param int $amountIncVat Minor units.
     *
     * @return array<int, array{id:string, amount:int}> Empty when it cannot be covered.
     */
    public function allocate($amountIncVat)
    {
        $outstanding = (int) $amountIncVat;

        if ($outstanding <= 0 || $outstanding > $this->refundable()) {
            return [];
        }

        $candidates = $this->captures;
        usort($candidates, static function ($a, $b) {
            return $b['remaining'] - $a['remaining'];
        });

        // A capture that can take the whole refund on its own takes it.
        foreach ($candidates as $capture) {
            if ($capture['remaining'] >= $outstanding) {
                return [['id' => $capture['id'], 'amount' => $outstanding]];
            }
        }

        $allocations = [];

        foreach ($candidates as $capture) {
            if ($outstanding <= 0) {
                break;
            }

            $take = min($capture['remaining'], $outstanding);

            if ($take <= 0) {
                continue;
            }

            $allocations[] = ['id' => $capture['id'], 'amount' => $take];
            $outstanding -= $take;
        }

        return $outstanding === 0 ? $allocations : [];
    }

    /**
     * The capture holding a set of lines, if one holds all of them.
     *
     * A line can only be refunded against the capture that captured it, so a
     * line-level refund spanning two captures has to become two refunds -- and
     * this reports when that is the case by finding no single capture.
     *
     * @param array $lines Briqpay cart lines.
     *
     * @return string Empty when no single capture holds them all.
     */
    public function captureHolding(array $lines)
    {
        foreach ($this->captures as $capture) {
            if ($capture['remaining'] > 0 && self::holdsAll($capture['cart'], $lines)) {
                return $capture['id'];
            }
        }

        return '';
    }

    /**
     * @param array $cart
     * @param array $lines
     *
     * @return bool
     */
    private static function holdsAll(array $cart, array $lines)
    {
        $available = [];

        foreach ($cart as $line) {
            if (!empty($line['reference'])) {
                $reference = (string) $line['reference'];
                $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
                $available[$reference] = (isset($available[$reference]) ? $available[$reference] : 0) + $quantity;
            }
        }

        foreach ($lines as $line) {
            $reference = isset($line['reference']) ? (string) $line['reference'] : '';
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;

            if ($reference === '' || !isset($available[$reference]) || $available[$reference] < $quantity) {
                return false;
            }

            $available[$reference] -= $quantity;
        }

        return true;
    }

    /**
     * @param array $session
     *
     * @return array<int, array{id:string, amount:int, refunded:int, remaining:int, cart:array}>
     */
    private static function read(array $session)
    {
        $refundedByCapture = [];

        foreach (self::entries($session, 'refunds') as $refund) {
            if (empty($refund['parentCaptureId'])) {
                continue;
            }

            $id = (string) $refund['parentCaptureId'];
            $amount = isset($refund['amountIncVat']) ? (int) $refund['amountIncVat'] : 0;
            $refundedByCapture[$id] = (isset($refundedByCapture[$id]) ? $refundedByCapture[$id] : 0) + $amount;
        }

        $captures = [];

        foreach (self::entries($session, 'captures') as $capture) {
            if (empty($capture['captureId'])) {
                continue;
            }

            $id = (string) $capture['captureId'];
            $amount = isset($capture['amountIncVat']) ? (int) $capture['amountIncVat'] : 0;
            $refunded = isset($refundedByCapture[$id]) ? $refundedByCapture[$id] : 0;

            $captures[] = [
                'id' => $id,
                'amount' => $amount,
                'refunded' => $refunded,
                'remaining' => max(0, $amount - $refunded),
                'cart' => isset($capture['cart']) && is_array($capture['cart']) ? $capture['cart'] : [],
            ];
        }

        return $captures;
    }

    /**
     * Approved entries only: a pending capture holds no money to refund, and a
     * rejected one never did.
     *
     * @param array  $session
     * @param string $key
     *
     * @return array<int, array>
     */
    private static function entries(array $session, $key)
    {
        $entries = isset($session['data'][$key]) && is_array($session['data'][$key])
            ? $session['data'][$key]
            : [];

        return array_values(array_filter($entries, static function ($entry) {
            if (!is_array($entry)) {
                return false;
            }

            $status = isset($entry['status']) ? (string) $entry['status'] : 'approved';

            return StatusMapper::isApproved($status);
        }));
    }
}
