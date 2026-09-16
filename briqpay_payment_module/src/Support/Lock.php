<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Coarse advisory lock backed by a unique index.
 *
 * A buyer returning to the confirmation page at the same moment a webhook
 * arrives will otherwise race to create the same order twice. Acquiring a lock
 * keyed on the cart makes the loser wait and then observe the winner's work.
 */
class Lock
{
    public const TABLE = 'briqpay_lock';

    /** Locks older than this are treated as abandoned by a crashed request. */
    public const TTL_SECONDS = 60;

    /** @var string */
    private $key;

    /** @var bool */
    private $acquired = false;

    /** @var string */
    private $token;

    /**
     * @param string $key
     */
    public function __construct($key)
    {
        $this->key = substr((string) $key, 0, 190);
        $this->token = uniqid('bq', true);
    }

    /**
     * @return string
     */
    public static function table()
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    /**
     * Convenience factory for the per-cart order-creation lock.
     *
     * @param int $cartId
     *
     * @return self
     */
    public static function forCart($cartId)
    {
        return new self('cart:' . (int) $cartId);
    }

    /**
     * Convenience factory for the per-order payment-operation lock.
     *
     * @param int $orderId
     *
     * @return self
     */
    public static function forOrder($orderId)
    {
        return new self('order:' . (int) $orderId);
    }

    /**
     * Try to take the lock, optionally waiting for a holder to release it.
     *
     * @param int $waitSeconds
     *
     * @return bool
     */
    public function acquire($waitSeconds = 0)
    {
        $deadline = microtime(true) + max(0, (int) $waitSeconds);

        do {
            $this->reapExpired();

            if ($this->insert()) {
                $this->acquired = true;

                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(250000);
        } while (true);
    }

    /**
     * @return bool
     */
    public function release()
    {
        if (!$this->acquired) {
            return false;
        }

        $this->acquired = false;

        return \Db::getInstance()->delete(
            self::TABLE,
            '`lock_key` = "' . pSQL($this->key) . '" AND `token` = "' . pSQL($this->token) . '"'
        );
    }

    /**
     * @return bool
     */
    public function isAcquired()
    {
        return $this->acquired;
    }

    /**
     * The unique index on lock_key is what makes this atomic across requests.
     *
     * @return bool
     */
    private function insert()
    {
        return (bool) \Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . self::table() . '` (`lock_key`, `token`, `date_add`)'
            . ' VALUES ("' . pSQL($this->key) . '", "' . pSQL($this->token) . '", "'
            . pSQL(date('Y-m-d H:i:s')) . '")'
        );
    }

    /**
     * @return void
     */
    private function reapExpired()
    {
        \Db::getInstance()->delete(
            self::TABLE,
            '`date_add` < "' . pSQL(date('Y-m-d H:i:s', time() - self::TTL_SECONDS)) . '"'
        );
    }

    public function __destruct()
    {
        // A fatal between acquire() and release() would otherwise leave the
        // lock held until the TTL reaper picks it up.
        if ($this->acquired) {
            $this->release();
        }
    }
}
