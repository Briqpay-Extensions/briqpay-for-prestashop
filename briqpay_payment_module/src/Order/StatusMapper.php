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
 * Translates Briqpay statuses into PrestaShop order states.
 *
 * The previous module hardcoded numeric state ids (2, 3, 5, 6, 7). Those are
 * only correct on a default English install -- any shop that reordered or
 * localised its statuses got orders moved to the wrong state. Everything here
 * resolves through Configuration instead.
 */
class StatusMapper
{
    /** Custom state created on install for authorised-but-uncaptured orders. */
    public const CONFIG_AWAITING_STATE = 'BRIQPAY_OS_AWAITING';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Briqpay order_status webhook values mapped onto transaction statuses.
     */
    public const ORDER_EVENT_MAP = [
        'order_pending' => self::STATUS_PENDING,
        'order_approved_not_captured' => self::STATUS_APPROVED,
        'order_rejected' => self::STATUS_REJECTED,
        'order_cancelled' => self::STATUS_CANCELLED,
    ];

    /**
     * Normalise either a transaction status or an order_status event name into
     * one of the four canonical statuses.
     *
     * @param string $status
     *
     * @return string
     */
    public static function normalise($status)
    {
        $status = strtolower(trim((string) $status));

        if (isset(self::ORDER_EVENT_MAP[$status])) {
            return self::ORDER_EVENT_MAP[$status];
        }

        switch ($status) {
            case 'approved':
            case 'captured':
            case 'complete':
            case 'completed':
                return self::STATUS_APPROVED;
            case 'rejected':
            case 'denied':
            case 'failed':
                return self::STATUS_REJECTED;
            case 'cancelled':
            case 'canceled':
            case 'aborted':
                return self::STATUS_CANCELLED;
            default:
                return self::STATUS_PENDING;
        }
    }

    /**
     * PrestaShop order state id for a Briqpay status.
     *
     * @param string $status
     *
     * @return int
     */
    public static function toOrderState($status)
    {
        switch (self::normalise($status)) {
            case self::STATUS_APPROVED:
                return (int) \Configuration::get('PS_OS_PAYMENT');
            case self::STATUS_REJECTED:
                return (int) \Configuration::get('PS_OS_ERROR');
            case self::STATUS_CANCELLED:
                return (int) \Configuration::get('PS_OS_CANCELED');
            case self::STATUS_PENDING:
            default:
                return self::getAwaitingState();
        }
    }

    /**
     * The module's own "Awaiting Briqpay payment" state, falling back to the
     * core preparation state if it was removed.
     *
     * @return int
     */
    public static function getAwaitingState()
    {
        $stateId = (int) \Configuration::get(self::CONFIG_AWAITING_STATE);

        if ($stateId > 0) {
            $state = new \OrderState($stateId);
            if (\Validate::isLoadedObject($state)) {
                return $stateId;
            }
        }

        return (int) \Configuration::get('PS_OS_PREPARATION');
    }

    /**
     * @return int
     */
    public static function getCapturedState()
    {
        return (int) \Configuration::get('PS_OS_PAYMENT');
    }

    /**
     * @return int
     */
    public static function getShippedState()
    {
        return (int) \Configuration::get('PS_OS_SHIPPED');
    }

    /**
     * @return int
     */
    public static function getRefundedState()
    {
        return (int) \Configuration::get('PS_OS_REFUND');
    }

    /**
     * @return int
     */
    public static function getCancelledState()
    {
        return (int) \Configuration::get('PS_OS_CANCELED');
    }

    /**
     * Whether a Briqpay status means the money is secured.
     *
     * @param string $status
     *
     * @return bool
     */
    public static function isApproved($status)
    {
        return self::normalise($status) === self::STATUS_APPROVED;
    }

    /**
     * Whether Briqpay has yet to decide.
     *
     * Distinct from rejected and cancelled: pending is expected to resolve on
     * its own, so the order page says so rather than treating it as a failure.
     *
     * @param string $status
     *
     * @return bool
     */
    public static function isPending($status)
    {
        return self::normalise($status) === self::STATUS_PENDING;
    }

    /**
     * Whether the status is terminal, so the module should stop reacting to it.
     *
     * @param string $status
     *
     * @return bool
     */
    public static function isFinal($status)
    {
        $normalised = self::normalise($status);

        return $normalised === self::STATUS_REJECTED || $normalised === self::STATUS_CANCELLED;
    }
}
