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
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * Briqpay calls webhooks from the internet, so that one URL has to be reachable
 * from outside -- unlike the redirect and terms URLs, which the buyer's own
 * browser follows and which are fine on whatever host the shop answers on.
 *
 * Wherever the two differ -- a reverse proxy terminating on another name, a
 * development tunnel in front of localhost -- the callback host has to be
 * stated separately, or the only way to receive a webhook is to move the whole
 * shop onto the public hostname.
 *
 * @covers \Briqpay\Payment\Config\Settings::toPublicUrl
 */
class PublicCallbackUrlTest extends TestCase
{
    private const SHOP_URL = 'http://localhost:8080/module/briqpay_payment_module/webhook';

    public function testTheShopUrlIsLeftAloneWhenNoOverrideIsSet(): void
    {
        self::assertSame(self::SHOP_URL, Settings::toPublicUrl(self::SHOP_URL));
    }

    public function testTheHostAndSchemeAreReplacedButThePathSurvives(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, 'https://example.trycloudflare.com');

        self::assertSame(
            'https://example.trycloudflare.com/module/briqpay_payment_module/webhook',
            Settings::toPublicUrl(self::SHOP_URL)
        );
    }

    /**
     * The port belongs to the host it was given with. Carrying the shop's port
     * over to the public host would send Briqpay to a port nothing serves.
     */
    public function testThePortComesFromTheOverrideNotTheShop(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, 'https://tunnel.test:8443');

        self::assertSame(
            'https://tunnel.test:8443/module/briqpay_payment_module/webhook',
            Settings::toPublicUrl(self::SHOP_URL)
        );
    }

    public function testAQueryStringIsKept(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, 'https://tunnel.test');

        self::assertSame(
            'https://tunnel.test/module/x/webhook?token=abc',
            Settings::toPublicUrl('http://localhost:8080/module/x/webhook?token=abc')
        );
    }

    /**
     * A shop published under a subdirectory keeps that prefix, or the callback
     * lands outside the application.
     */
    public function testAPathPrefixOnTheOverrideIsPreserved(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, 'https://example.com/shop');

        self::assertSame(
            'https://example.com/shop/module/briqpay_payment_module/webhook',
            Settings::toPublicUrl(self::SHOP_URL)
        );
    }

    public function testATrailingSlashOnTheOverrideDoesNotDoubleUp(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, 'https://example.com/');

        self::assertSame(
            'https://example.com/module/briqpay_payment_module/webhook',
            Settings::toPublicUrl(self::SHOP_URL)
        );
    }

    public function testABareHostDefaultsToHttps(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, '//tunnel.test');

        self::assertSame(
            'https://tunnel.test/module/briqpay_payment_module/webhook',
            Settings::toPublicUrl(self::SHOP_URL)
        );
    }

    /**
     * A value that names no host cannot be turned into a callback address, and
     * silently dropping the path would be worse than ignoring the setting.
     */
    public function testAnUnusableOverrideIsIgnored(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, 'not a url');

        self::assertSame(self::SHOP_URL, Settings::toPublicUrl(self::SHOP_URL));
    }

    public function testWhitespaceOnlyOverrideIsIgnored(): void
    {
        \Configuration::updateValue(Settings::WEBHOOK_BASE_URL, '   ');

        self::assertSame(self::SHOP_URL, Settings::toPublicUrl(self::SHOP_URL));
    }
}
