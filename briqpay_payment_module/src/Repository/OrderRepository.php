<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Repository;

use Briqpay\Payment\Config\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Briqpay payment record attached to a PrestaShop order.
 */
class OrderRepository
{
    public const TABLE = 'briqpay_order';
    public const LEGACY_TABLE = 'briqpay_payment_module_orders';

    /**
     * @return string
     */
    public static function table()
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    /**
     * @param int $orderId
     *
     * @return array|null
     */
    public function findByOrderId($orderId)
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . self::table() . '` WHERE `id_order` = ' . (int) $orderId
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
     * Insert or update the Briqpay record for a PrestaShop order.
     *
     * @param array $data
     *
     * @return bool
     */
    public function save(array $data)
    {
        $orderId = isset($data['id_order']) ? (int) $data['id_order'] : 0;
        $row = $this->sanitise($data);
        $row['date_upd'] = date('Y-m-d H:i:s');

        if ($orderId > 0 && $this->findByOrderId($orderId) !== null) {
            return \Db::getInstance()->update(self::TABLE, $row, '`id_order` = ' . $orderId);
        }

        $row['date_add'] = date('Y-m-d H:i:s');

        return \Db::getInstance()->insert(self::TABLE, $row);
    }

    /**
     * @param int   $orderId
     * @param array $data
     *
     * @return bool
     */
    public function updateByOrderId($orderId, array $data)
    {
        $row = $this->sanitise($data);
        $row['date_upd'] = date('Y-m-d H:i:s');

        return \Db::getInstance()->update(self::TABLE, $row, '`id_order` = ' . (int) $orderId);
    }

    /**
     * Add to the captured/refunded running totals without a read-modify-write
     * race between a webhook and an admin action.
     *
     * @param int    $orderId
     * @param string $column  Either 'captured_amount' or 'refunded_amount'.
     * @param int    $amount  Minor units.
     *
     * @return bool
     */
    public function incrementAmount($orderId, $column, $amount)
    {
        if (!in_array($column, ['captured_amount', 'refunded_amount'], true)) {
            return false;
        }

        return \Db::getInstance()->execute(
            'UPDATE `' . self::table() . '`'
            . ' SET `' . bqSQL($column) . '` = `' . bqSQL($column) . '` + ' . (int) $amount
            . ', `date_upd` = "' . pSQL(date('Y-m-d H:i:s')) . '"'
            . ' WHERE `id_order` = ' . (int) $orderId
        );
    }

    /**
     * Whitelist columns so a caller can never smuggle arbitrary SQL in an
     * unexpected array key.
     *
     * @param array $data
     *
     * @return array
     */
    private function sanitise(array $data)
    {
        $intColumns = ['id_order', 'id_cart', 'captured_amount', 'refunded_amount', 'total_amount', 'auto_captured'];
        $stringColumns = [
            'session_id', 'order_reference', 'status', 'payment_method', 'psp_name',
            'psp_display_name', 'reservation_id', 'currency', 'customer_type',
            'company_name', 'company_cin', 'merchant_id',
            'hpp_id', 'hpp_url', 'hpp_flow', 'hpp_created',
        ];

        $row = [];

        foreach ($intColumns as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = (int) $data[$column];
            }
        }

        foreach ($stringColumns as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = pSQL((string) $data[$column]);
            }
        }

        return $row;
    }

    /**
     * A blank record, so callers can render a panel for an order that has no
     * Briqpay payment yet -- the back-office order awaiting a payment link.
     *
     * @param int $orderId
     * @param int $cartId
     *
     * @return array<string, mixed>
     */
    public static function emptyRecord($orderId, $cartId = 0)
    {
        return [
            'id_order' => (int) $orderId,
            'id_cart' => (int) $cartId,
            'session_id' => '',
            'order_reference' => '',
            'status' => '',
            'payment_method' => '',
            'psp_name' => '',
            'psp_display_name' => '',
            'reservation_id' => '',
            'currency' => '',
            'customer_type' => '',
            'company_name' => '',
            'company_cin' => '',
            'merchant_id' => '',
            'total_amount' => 0,
            'captured_amount' => 0,
            'refunded_amount' => 0,
            'auto_captured' => 0,
            'hpp_id' => '',
            'hpp_url' => '',
            'hpp_flow' => '',
            'hpp_created' => null,
        ];
    }

    /**
     * Deep link into the Briqpay dashboard for a session.
     *
     * @param string $sessionId
     * @param string $merchantId
     *
     * @return string
     */
    public static function buildDashboardLink($sessionId, $merchantId)
    {
        return Settings::DASHBOARD_URL . rawurlencode((string) $sessionId)
            . '?test=' . Settings::getBackOfficeMode()
            . '&merchantId=' . rawurlencode((string) $merchantId);
    }
}
