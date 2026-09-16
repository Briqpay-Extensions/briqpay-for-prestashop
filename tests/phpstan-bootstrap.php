<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Constants PrestaShop defines at runtime, so PHPStan does not treat the
 * module's `if (!defined('_PS_VERSION_')) { exit; }` guards as dead code.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', '/var/www/html/modules/');
}

if (!defined('_PS_IMG_DIR_')) {
    define('_PS_IMG_DIR_', '/var/www/html/img/');
}

if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}
