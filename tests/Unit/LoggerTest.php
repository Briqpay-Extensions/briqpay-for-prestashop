<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Briqpay\Payment\Tests\Unit;

use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Support\Logger;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Support\Logger
 */
class LoggerTest extends TestCase
{
    public function testLoggingCanBeTurnedOffEntirely(): void
    {
        \Configuration::updateValue(Settings::LOGGING_ENABLED, 0);

        Logger::error('should not appear');

        self::assertCount(0, \PrestaShopLogger::$logs);
    }

    public function testErrorLevelSuppressesInfoAndDebug(): void
    {
        \Configuration::updateValue(Settings::LOGGING_ENABLED, 1);
        \Configuration::updateValue(Settings::LOG_LEVEL, Logger::LEVEL_ERROR);

        Logger::debug('debug');
        Logger::info('info');
        Logger::error('error');

        self::assertCount(1, \PrestaShopLogger::$logs);
        self::assertStringContainsString('error', \PrestaShopLogger::$logs[0]['message']);
    }

    public function testDebugLevelLetsEverythingThrough(): void
    {
        \Configuration::updateValue(Settings::LOGGING_ENABLED, 1);
        \Configuration::updateValue(Settings::LOG_LEVEL, Logger::LEVEL_DEBUG);

        Logger::debug('debug');
        Logger::info('info');
        Logger::error('error');

        self::assertCount(3, \PrestaShopLogger::$logs);
    }

    public function testMessagesArePrefixedForEasyFiltering(): void
    {
        \Configuration::updateValue(Settings::LOGGING_ENABLED, 1);
        \Configuration::updateValue(Settings::LOG_LEVEL, Logger::LEVEL_ERROR);

        Logger::error('something broke');

        self::assertStringStartsWith('[Briqpay]', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Logs get pasted into support tickets, so credentials and buyer PII must
     * never reach them.
     */
    public function testSensitiveValuesAreRedacted(): void
    {
        $clean = Logger::redact([
            'merchantId' => 'merchant-1',
            'secret' => 'super-secret',
            'email' => 'anna@example.com',
            'phone' => '+46701234567',
            'amount' => 12500,
        ]);

        self::assertSame('merchant-1', $clean['merchantId'], 'non-sensitive values survive');
        self::assertSame(12500, $clean['amount']);
        self::assertSame('***', $clean['secret']);
        self::assertSame('***', $clean['email']);
        self::assertSame('***', $clean['phone']);
    }

    public function testRedactionReachesNestedPayloads(): void
    {
        $clean = Logger::redact([
            'payload' => [
                'data' => [
                    'billing' => ['email' => 'anna@example.com', 'city' => 'Stockholm'],
                ],
            ],
        ]);

        self::assertSame('***', $clean['payload']['data']['billing']['email']);
        self::assertSame('Stockholm', $clean['payload']['data']['billing']['city']);
    }

    public function testRedactionIsCaseInsensitive(): void
    {
        $clean = Logger::redact(['Secret' => 'x', 'EMAIL' => 'y', 'clientToken' => 'z']);

        self::assertSame('***', $clean['Secret']);
        self::assertSame('***', $clean['EMAIL']);
        self::assertSame('***', $clean['clientToken']);
    }

    public function testRedactedContextIsWrittenToTheLogLine(): void
    {
        \Configuration::updateValue(Settings::LOGGING_ENABLED, 1);
        \Configuration::updateValue(Settings::LOG_LEVEL, Logger::LEVEL_ERROR);

        Logger::error('API failure', ['secret' => 'super-secret', 'status' => 500]);

        $message = \PrestaShopLogger::$logs[0]['message'];

        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringContainsString('***', $message);
        self::assertStringContainsString('500', $message);
    }

    public function testShouldLogReflectsTheConfiguredThreshold(): void
    {
        \Configuration::updateValue(Settings::LOGGING_ENABLED, 1);
        \Configuration::updateValue(Settings::LOG_LEVEL, Logger::LEVEL_INFO);

        self::assertFalse(Logger::shouldLog(Logger::LEVEL_DEBUG));
        self::assertTrue(Logger::shouldLog(Logger::LEVEL_INFO));
        self::assertTrue(Logger::shouldLog(Logger::LEVEL_ERROR));
    }
}
