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

use Briqpay\Payment\Builder\Locale;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Builder\Locale
 */
class LocaleTest extends TestCase
{
    /**
     * @dataProvider localeProvider
     */
    public function testNormalise(string $input, string $expected): void
    {
        self::assertSame($expected, Locale::normalise($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function localeProvider(): array
    {
        return [
            // PrestaShop stores BCP-47 with an uppercase region; Briqpay wants
            // it lowercased.
            'swedish' => ['sv-SE', 'sv-se'],
            'danish' => ['da-DK', 'da-dk'],
            'german' => ['de-DE', 'de-de'],
            'underscores' => ['sv_SE', 'sv-se'],
            'already lowercase' => ['fi-fi', 'fi-fi'],

            // A regional variant is preserved.
            'austrian german' => ['de-AT', 'de-at'],

            // Bare language codes: PrestaShop installs often have no region.
            'bare swedish' => ['sv', 'sv-se'],
            'bare danish' => ['da', 'da-dk'],
            'bare finnish' => ['fi', 'fi-fi'],
            'bare norwegian' => ['no', 'nb-no'],

            // Anything unrecognised falls back rather than being forwarded.
            'unknown language' => ['xx', 'en-gb'],
            'empty' => ['', 'en-gb'],
            'junk' => ['!!!', 'en-gb'],
        ];
    }

    /**
     * Briqpay does not recognise en-US. PrestaShop ships English as en-US by
     * default, so an untranslated shop would otherwise send a locale Briqpay
     * cannot act on -- and the locale is part of how it decides which payment
     * methods to present.
     *
     * @dataProvider englishProvider
     */
    public function testEveryEnglishVariantBecomesEnGb(string $input): void
    {
        self::assertSame('en-gb', Locale::normalise($input));
    }

    /**
     * @return array<int, array{0: string}>
     */
    public function englishProvider(): array
    {
        return [['en-US'], ['en_US'], ['en-GB'], ['en-AU'], ['en'], ['EN-US']];
    }

    public function testNeverEmitsAnUppercaseRegion(): void
    {
        foreach (['sv-SE', 'en-US', 'de_DE', 'PT-BR'] as $input) {
            $result = Locale::normalise($input);

            self::assertSame(
                strtolower($result),
                $result,
                sprintf('%s produced a locale with uppercase characters', $input)
            );
        }
    }

    public function testAlwaysReturnsALanguageRegionPair(): void
    {
        foreach (['sv', 'en-US', '', 'xx', 'de-AT', '!!'] as $input) {
            self::assertMatchesRegularExpression(
                '/^[a-z]{2}-[a-z]{2}$/',
                Locale::normalise($input),
                sprintf('%s did not produce a language-region pair', $input)
            );
        }
    }

    public function testPrefersTheLanguageLocaleOverTheIsoCode(): void
    {
        $language = new \Language();
        $language->locale = 'sv-SE';
        $language->iso_code = 'en';

        self::assertSame('sv-se', Locale::fromLanguage($language));
    }

    /**
     * Language::$locale only exists from PrestaShop 1.7.6.
     */
    public function testFallsBackToTheIsoCodeOnOlderPrestaShop(): void
    {
        $language = new \Language();
        $language->locale = '';
        $language->language_code = '';
        $language->iso_code = 'da';

        self::assertSame('da-dk', Locale::fromLanguage($language));
    }
}
