<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Install;

use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Order\StatusMapper;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Repository\SessionRepository;
use Briqpay\Payment\Support\Lock;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Schema, hooks, order states and configuration defaults.
 */
class Installer
{
    /** Hooks the module needs in order to function. */
    public const HOOKS = [
        'paymentOptions',
        'displayPaymentTop',
        'actionOrderStatusPostUpdate',
        'actionOrderSlipAdd',
        'displayAdminOrderMainBottom',
        'actionCartUpdateQuantityBefore',
        'header',
    ];

    /** @var \Module */
    private $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    /**
     * @return bool
     */
    public function install()
    {
        return $this->createTables()
            && $this->migrateLegacyTable()
            && $this->registerHooks()
            && $this->installOrderStates()
            && $this->installDefaults();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        // Order states and the payment tables are intentionally left in place:
        // dropping them would orphan the payment history of past orders.
        return $this->removeSettings();
    }

    /**
     * @return bool
     */
    public function createTables()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';

        $statements = [
            'CREATE TABLE IF NOT EXISTS `' . SessionRepository::table() . '` (
                `id_briqpay_session` INT(11) NOT NULL AUTO_INCREMENT,
                `id_cart` INT(11) NOT NULL,
                `session_id` VARCHAR(191) NOT NULL,
                `customer_type` VARCHAR(32) NOT NULL DEFAULT "",
                `fingerprint` CHAR(64) NOT NULL DEFAULT "",
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_briqpay_session`),
                UNIQUE KEY `briqpay_session_cart` (`id_cart`),
                KEY `briqpay_session_session` (`session_id`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

            'CREATE TABLE IF NOT EXISTS `' . OrderRepository::table() . '` (
                `id_briqpay_order` INT(11) NOT NULL AUTO_INCREMENT,
                `id_order` INT(11) NOT NULL,
                `id_cart` INT(11) NOT NULL DEFAULT 0,
                `session_id` VARCHAR(191) NOT NULL,
                `order_reference` VARCHAR(64) NOT NULL DEFAULT "",
                `status` VARCHAR(64) NOT NULL DEFAULT "",
                `payment_method` VARCHAR(191) NOT NULL DEFAULT "",
                `psp_name` VARCHAR(191) NOT NULL DEFAULT "",
                `psp_display_name` VARCHAR(191) NOT NULL DEFAULT "",
                `reservation_id` VARCHAR(191) NOT NULL DEFAULT "",
                `currency` VARCHAR(8) NOT NULL DEFAULT "",
                `customer_type` VARCHAR(32) NOT NULL DEFAULT "",
                `company_name` VARCHAR(191) NOT NULL DEFAULT "",
                `company_cin` VARCHAR(64) NOT NULL DEFAULT "",
                `merchant_id` VARCHAR(191) NOT NULL DEFAULT "",
                `total_amount` INT(11) NOT NULL DEFAULT 0,
                `captured_amount` INT(11) NOT NULL DEFAULT 0,
                `refunded_amount` INT(11) NOT NULL DEFAULT 0,
                `auto_captured` TINYINT(1) NOT NULL DEFAULT 0,
                `hpp_id` VARCHAR(191) NOT NULL DEFAULT "",
                `hpp_url` VARCHAR(512) NOT NULL DEFAULT "",
                `hpp_flow` VARCHAR(32) NOT NULL DEFAULT "",
                `hpp_created` DATETIME NULL DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_briqpay_order`),
                UNIQUE KEY `briqpay_order_order` (`id_order`),
                KEY `briqpay_order_session` (`session_id`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',

            'CREATE TABLE IF NOT EXISTS `' . Lock::table() . '` (
                `lock_key` VARCHAR(191) NOT NULL,
                `token` VARCHAR(64) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`lock_key`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;',
        ];

        foreach ($statements as $sql) {
            if (!\Db::getInstance()->execute($sql)) {
                Logger::error('Failed to create a Briqpay table.', ['error' => \Db::getInstance()->getMsgError()]);

                return false;
            }
        }

        return true;
    }

    /**
     * Copy rows from the 1.x table so existing orders keep their payment panel.
     *
     * @return bool
     */
    public function migrateLegacyTable()
    {
        $legacy = _DB_PREFIX_ . OrderRepository::LEGACY_TABLE;

        if (!$this->tableExists($legacy)) {
            return true;
        }

        $sql = 'INSERT IGNORE INTO `' . OrderRepository::table() . '`
                (`id_order`, `session_id`, `order_reference`, `status`, `payment_method`,
                 `psp_name`, `psp_display_name`, `reservation_id`, `captured_amount`,
                 `auto_captured`, `date_add`, `date_upd`)
                SELECT
                    l.`presta_order_id`,
                    l.`sessionid`,
                    l.`presta_reference`,
                    l.`status`,
                    l.`payment_method`,
                    l.`payment_method`,
                    l.`pspname`,
                    l.`reservationid`,
                    0,
                    l.`captured`,
                    l.`date_created`,
                    l.`date_created`
                FROM `' . bqSQL($legacy) . '` l
                WHERE l.`presta_order_id` > 0';

        if (!\Db::getInstance()->execute($sql)) {
            Logger::error('Failed to migrate the legacy Briqpay table.', [
                'error' => \Db::getInstance()->getMsgError(),
            ]);

            // A failed migration must not block the upgrade; the new table is
            // still usable for orders placed from now on.
            return true;
        }

        Logger::info('Migrated legacy Briqpay order records.');

        return true;
    }

    /**
     * @return bool
     */
    public function registerHooks()
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->module->registerHook($hook)) {
                Logger::error('Failed to register a hook.', ['hook' => $hook]);

                return false;
            }
        }

        return true;
    }

    /**
     * Create the "Awaiting Briqpay payment" state used while an order is
     * authorised but not yet settled.
     *
     * @return bool
     */
    public function installOrderStates()
    {
        $existing = (int) \Configuration::get(StatusMapper::CONFIG_AWAITING_STATE);

        if ($existing > 0) {
            $state = new \OrderState($existing);
            if (\Validate::isLoadedObject($state)) {
                return true;
            }
        }

        $state = new \OrderState();
        $state->name = [];

        foreach (\Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'Awaiting Briqpay payment';
        }

        $state->send_email = false;
        $state->invoice = false;
        $state->color = '#4169E1';
        $state->unremovable = true;
        $state->logable = false;
        $state->delivery = false;
        $state->hidden = false;
        $state->shipped = false;
        $state->paid = false;
        $state->deleted = false;
        $state->module_name = $this->module->name;

        if (!$state->add()) {
            Logger::error('Failed to create the Briqpay order state.');

            return false;
        }

        $logo = _PS_MODULE_DIR_ . $this->module->name . '/logo.png';
        if (is_file($logo)) {
            @copy($logo, _PS_IMG_DIR_ . 'os/' . (int) $state->id . '.gif');
        }

        return \Configuration::updateValue(StatusMapper::CONFIG_AWAITING_STATE, (int) $state->id);
    }

    /**
     * @return bool
     */
    public function installDefaults()
    {
        foreach (Settings::defaults() as $key => $value) {
            if (\Configuration::hasKey($key)) {
                continue;
            }

            \Configuration::updateValue($key, is_bool($value) ? (int) $value : $value);
        }

        return true;
    }

    /**
     * @return bool
     */
    public function removeSettings()
    {
        foreach (array_keys(Settings::defaults()) as $key) {
            \Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * @param string $table
     *
     * @return bool
     */
    private function tableExists($table)
    {
        $result = \Db::getInstance()->executeS(
            'SHOW TABLES LIKE "' . pSQL($table) . '"'
        );

        return is_array($result) && count($result) > 0;
    }
}
