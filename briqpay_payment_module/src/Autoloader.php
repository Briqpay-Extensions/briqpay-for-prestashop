<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Minimal PSR-4 autoloader for the module's own namespace.
 *
 * PrestaShop modules cannot rely on a Composer vendor directory being present
 * on every install, so the runtime uses this instead. Composer is still used
 * for the development toolchain (PHPUnit, PHPStan, php-cs-fixer).
 */
class Autoloader
{
    public const NAMESPACE_PREFIX = 'Briqpay\\Payment\\';

    /** @var bool */
    private static $registered = false;

    /**
     * @param string $baseDirectory Directory holding the namespace root.
     *
     * @return void
     */
    public static function register($baseDirectory)
    {
        if (self::$registered) {
            return;
        }

        $baseDirectory = rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register(function ($class) use ($baseDirectory) {
            $prefixLength = strlen(self::NAMESPACE_PREFIX);

            if (strncmp(self::NAMESPACE_PREFIX, $class, $prefixLength) !== 0) {
                return;
            }

            $relative = substr($class, $prefixLength);
            $path = $baseDirectory . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        });

        self::$registered = true;
    }
}
