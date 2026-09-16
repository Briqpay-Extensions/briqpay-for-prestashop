<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Test bootstrap.
 *
 * Defines just enough of PrestaShop's global surface for the module's classes
 * to be exercised in isolation. The stubs are deliberately dumb data holders:
 * anything with real behaviour would be testing PrestaShop rather than Briqpay.
 */

declare(strict_types=1);

define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
define('_PS_MODULE_DIR_', __DIR__ . '/../briqpay_payment_module/../');
define('_PS_IMG_DIR_', sys_get_temp_dir() . '/');
define('_MYSQL_ENGINE_', 'InnoDB');

// Load Composer explicitly so the bootstrap also works outside PHPUnit, e.g.
// from a one-off script.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/../briqpay_payment_module/src/Autoloader.php';
\Briqpay\Payment\Autoloader::register(__DIR__ . '/../briqpay_payment_module/src');

require_once __DIR__ . '/Stub/PrestaShopStubs.php';
require_once __DIR__ . '/Support/TestCase.php';
