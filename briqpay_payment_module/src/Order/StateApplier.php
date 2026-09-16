<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Order;

use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Support\Lock;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Decides and applies the PrestaShop order state after a payment operation.
 *
 * The same capture, refund or cancel can reach the shop two ways: the merchant
 * presses the button on the order page, or Briqpay reports it on a webhook.
 * Both have to land on the same state, so the rules live here rather than being
 * written out twice and drifting apart.
 *
 * Applying it from the button as well as the webhook is what makes the order
 * page truthful straight away. Webhook delivery is not instant -- a refund has
 * been seen to take ninety seconds -- and until it lands a merchant who has
 * just refunded an order is looking at one that still says Payment accepted,
 * with no way to tell whether the refund worked.
 */
class StateApplier
{
    /**
     * Where an order belongs once it has been captured.
     *
     * @return int
     */
    public static function stateAfterCapture()
    {
        // Auto-capturing methods (card, Swish, ...) settle without the merchant
        // doing anything, so the shop can optionally move the order straight on.
        if (Settings::getBool(Settings::AUTO_CAPTURE_STATE)) {
            return StatusMapper::getShippedState();
        }

        return StatusMapper::getCapturedState();
    }

    /**
     * Where an order belongs once it has been refunded.
     *
     * Only a full refund moves it: a partial is recorded on the payment panel
     * and left for the merchant to reconcile, because the shop cannot know
     * whether more is coming.
     *
     * @param int $refunded Minor units.
     * @param int $captured Minor units.
     *
     * @return int Zero when the state should not move.
     */
    public static function stateAfterRefund($refunded, $captured)
    {
        $refunded = (int) $refunded;
        $captured = (int) $captured;

        if ($refunded > 0 && $captured > 0 && $refunded >= $captured) {
            return StatusMapper::getRefundedState();
        }

        return 0;
    }

    /**
     * @return int
     */
    public static function stateAfterCancel()
    {
        return StatusMapper::getCancelledState();
    }

    /**
     * Move the order, if it is not already there.
     *
     * Takes the order lock, so it must not be called by something already
     * holding it.
     *
     * Never throws: a state change that fails must not turn a payment operation
     * that already succeeded at Briqpay into an error on screen, which would
     * invite the merchant to run it a second time.
     *
     * That means catching \Throwable rather than \Exception. OrderHistory
     * reaches deep into PrestaShop -- invoices, stock, translated emails -- and
     * a misconfigured shop surfaces there as a TypeError rather than an
     * exception. Catching only \Exception lets it escape and kills the request
     * after the money has already moved, which is the one outcome this method
     * exists to prevent.
     *
     * @param \Order $order
     * @param int    $stateId
     * @param string $context Session id or webhook event, for the log.
     *
     * @return bool Whether the order was moved.
     */
    public function apply(\Order $order, $stateId, $context = '')
    {
        $stateId = (int) $stateId;

        if ($stateId <= 0 || (int) $order->getCurrentState() === $stateId) {
            return false;
        }

        $lock = Lock::forOrder((int) $order->id);
        if (!$lock->acquire(10)) {
            Logger::error('Could not lock the order to change its state.', ['orderId' => (int) $order->id]);

            return false;
        }

        try {
            // Re-read inside the lock: the webhook and the button race whenever
            // the merchant presses one, and whichever arrives second must not
            // write the state again and send a second email.
            $fresh = new \Order((int) $order->id);
            if ((int) $fresh->getCurrentState() === $stateId) {
                return false;
            }

            $history = new \OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($stateId, (int) $order->id, true);
            $history->addWithemail(true);

            Logger::info('Order state changed.', [
                'orderId' => (int) $order->id,
                'stateId' => $stateId,
                'context' => $context,
            ]);

            return true;
        } catch (\Throwable $e) {
            Logger::error('Failed to change the order state.', [
                'orderId' => (int) $order->id,
                'stateId' => $stateId,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }
}
