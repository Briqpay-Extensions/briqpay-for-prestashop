<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Support;

use Briqpay\Payment\Config\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Thin, level-aware wrapper around PrestaShopLogger.
 *
 * Logging is merchant-toggleable and every payload passes through a redactor
 * so that credentials and buyer PII never reach the log table.
 */
class Logger
{
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_ERROR = 'error';

    /** PrestaShopLogger severities. */
    public const SEVERITY_INFO = 1;
    public const SEVERITY_WARNING = 2;
    public const SEVERITY_ERROR = 3;

    /** Keys whose values are replaced before anything is written to the log. */
    public const REDACTED_KEYS = [
        'secret', 'password', 'passwd', 'authorization', 'clienttoken', 'token',
        'email', 'phonenumber', 'phone', 'vat_number', 'cin', 'ssn',
    ];

    /** @var array<string, int> */
    private static $priorities = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_ERROR => 2,
    ];

    /**
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public static function debug($message, array $context = [])
    {
        self::log(self::LEVEL_DEBUG, $message, $context, self::SEVERITY_INFO);
    }

    /**
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public static function info($message, array $context = [])
    {
        self::log(self::LEVEL_INFO, $message, $context, self::SEVERITY_INFO);
    }

    /**
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public static function error($message, array $context = [])
    {
        self::log(self::LEVEL_ERROR, $message, $context, self::SEVERITY_ERROR);
    }

    /**
     * @param string $level
     * @param string $message
     * @param array  $context
     * @param int    $severity
     *
     * @return void
     */
    private static function log($level, $message, array $context, $severity)
    {
        if (!self::shouldLog($level)) {
            return;
        }

        $line = '[Briqpay] ' . $message;
        if (!empty($context)) {
            $line .= ' ' . json_encode(self::redact($context));
        }

        \PrestaShopLogger::addLog($line, $severity, null, 'Briqpay', null, true);
    }

    /**
     * @param string $level
     *
     * @return bool
     */
    public static function shouldLog($level)
    {
        if (!Settings::getBool(Settings::LOGGING_ENABLED)) {
            return false;
        }

        $configured = Settings::getString(Settings::LOG_LEVEL);
        $threshold = isset(self::$priorities[$configured]) ? self::$priorities[$configured] : self::$priorities[self::LEVEL_ERROR];
        $current = isset(self::$priorities[$level]) ? self::$priorities[$level] : self::$priorities[self::LEVEL_ERROR];

        return $current >= $threshold;
    }

    /**
     * Recursively mask sensitive values so logs stay safe to share with support.
     *
     * @param array $data
     *
     * @return array
     */
    public static function redact(array $data)
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = self::redact($value);
                continue;
            }

            if (is_string($key) && in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $clean[$key] = '***';
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
