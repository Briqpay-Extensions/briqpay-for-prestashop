<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Install\Installer;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade from the 1.x module to 2.0.0.
 *
 * Creates the new tables, copies the 1.x payment records across, registers the
 * hooks 2.0 relies on, and carries the old settings over to their new keys.
 *
 * The 1.x `override/controllers/front/CartController.php` redirected the cart
 * to a standalone Briqpay page. 2.0 embeds the iframe in PrestaShop's own
 * checkout instead, so that override is removed here -- leaving it in place
 * would keep hijacking the cart.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    $installer = new Installer($module);

    if (!$installer->createTables()) {
        return false;
    }

    $installer->migrateLegacyTable();
    $installer->installOrderStates();
    $installer->installDefaults();

    if (!$installer->registerHooks()) {
        return false;
    }

    briqpayUpgradeRemoveLegacyOverrides($module);
    briqpayUpgradeMigrateSettings();
    briqpayUpgradeUnregisterLegacyHooks($module);

    Logger::info('Upgraded the Briqpay module to 2.0.0.');

    return true;
}

/**
 * Delete the 1.x controller overrides.
 *
 * @param Module $module
 *
 * @return void
 */
function briqpayUpgradeRemoveLegacyOverrides($module)
{
    $overrides = [
        _PS_OVERRIDE_DIR_ . 'controllers/front/CartController.php',
        _PS_OVERRIDE_DIR_ . 'controllers/front/OrderConfirmationController.php',
    ];

    foreach ($overrides as $path) {
        if (!is_file($path)) {
            continue;
        }

        // Only remove a file that is recognisably ours; a merchant may have
        // written their own override at the same path.
        $contents = (string) file_get_contents($path);
        if (strpos($contents, 'Briqpay') === false) {
            Logger::info('Left a third-party override in place.', ['path' => $path]);
            continue;
        }

        if (@unlink($path)) {
            Logger::info('Removed a legacy Briqpay override.', ['path' => $path]);
        } else {
            Logger::error('Could not remove a legacy Briqpay override; delete it by hand.', ['path' => $path]);
        }
    }

    // PrestaShop caches the override class map.
    $cache = _PS_CACHE_DIR_ . 'class_index.php';
    if (is_file($cache)) {
        @unlink($cache);
    }
}

/**
 * Carry 1.x settings over to their 2.0 equivalents.
 *
 * @return void
 */
function briqpayUpgradeMigrateSettings()
{
    // 1.x stored 'consumer' | 'business' | 'both'. 2.0 replaces 'both' -- which
    // needed a manual switcher -- with automatic detection from the address.
    $legacyType = (string) Configuration::get('BRIQPAY_CUSTOMER_TYPE');

    if ($legacyType === 'both' || $legacyType === '') {
        Configuration::updateValue(Settings::CUSTOMER_TYPE, Settings::CUSTOMER_TYPE_AUTO);
    }

    // These drove the 1.x standalone checkout's own address and shipping
    // widgets, which no longer exist.
    foreach (['BRIQPAY_SHOW_SWITCHER', 'BRIQPAY_ADDRESS_SWITCHER', 'BRIQPAY_SHIPPING_SWITCHER'] as $obsolete) {
        Configuration::deleteByName($obsolete);
    }

    // 1.x hardcoded "https://terms.com" into every session payload. Leave the
    // new setting empty so it falls back to the shop's own conditions page.
    if (!Configuration::hasKey(Settings::TERMS_URL)) {
        Configuration::updateValue(Settings::TERMS_URL, '');
    }
}

/**
 * Drop the hooks 1.x registered that 2.0 no longer implements.
 *
 * @param Module $module
 *
 * @return void
 */
function briqpayUpgradeUnregisterLegacyHooks($module)
{
    foreach (['actionOrderStatusUpdate'] as $hook) {
        $hookId = (int) Hook::getIdByName($hook);

        if ($hookId > 0) {
            $module->unregisterHook($hookId);
        }
    }
}
