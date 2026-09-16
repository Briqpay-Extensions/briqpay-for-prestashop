<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cart <-> Briqpay session mapping.
 *
 * The previous module kept the session id in the shopper's cookie, which meant
 * a webhook arriving without a browser session had no way back to the cart and
 * a cleared cookie silently orphaned a live payment session. It lives in the
 * database now.
 */
class SessionRepository
{
    public const TABLE = 'briqpay_session';

    /**
     * @return string
     */
    public static function table()
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    /**
     * @param int $cartId
     *
     * @return array|null
     */
    public function findByCartId($cartId)
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . self::table() . '` WHERE `id_cart` = ' . (int) $cartId
        );

        return is_array($row) && !empty($row) ? $row : null;
    }

    /**
     * @param string $sessionId
     *
     * @return array|null
     */
    public function findBySessionId($sessionId)
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . self::table() . '` WHERE `session_id` = "' . pSQL($sessionId) . '"'
        );

        return is_array($row) && !empty($row) ? $row : null;
    }

    /**
     * @param int    $cartId
     * @param string $sessionId
     * @param string $customerType
     * @param string $fingerprint
     *
     * @return bool
     */
    public function save($cartId, $sessionId, $customerType, $fingerprint)
    {
        $data = [
            'id_cart' => (int) $cartId,
            'session_id' => pSQL($sessionId),
            'customer_type' => pSQL($customerType),
            'fingerprint' => pSQL($fingerprint),
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($this->findByCartId($cartId) !== null) {
            return \Db::getInstance()->update(self::TABLE, $data, '`id_cart` = ' . (int) $cartId);
        }

        $data['date_add'] = date('Y-m-d H:i:s');

        return \Db::getInstance()->insert(self::TABLE, $data);
    }

    /**
     * @param int    $cartId
     * @param string $fingerprint
     *
     * @return bool
     */
    public function updateFingerprint($cartId, $fingerprint)
    {
        return \Db::getInstance()->update(
            self::TABLE,
            ['fingerprint' => pSQL($fingerprint), 'date_upd' => date('Y-m-d H:i:s')],
            '`id_cart` = ' . (int) $cartId
        );
    }

    /**
     * @param int $cartId
     *
     * @return bool
     */
    public function deleteByCartId($cartId)
    {
        return \Db::getInstance()->delete(self::TABLE, '`id_cart` = ' . (int) $cartId);
    }
}
