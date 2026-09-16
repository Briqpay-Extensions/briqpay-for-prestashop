<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Builder;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Maps PrestaShop locales onto the form Briqpay expects.
 *
 * Briqpay wants a lowercase `language-region` pair. PrestaShop stores locales
 * as BCP-47 with an uppercase region -- `en-US`, `sv-SE` -- and older versions
 * expose only the bare language iso code, so the raw value cannot be forwarded.
 *
 * The locale is not cosmetic: it is part of how Briqpay decides which payment
 * methods to present, so an unrecognised value can leave a shopper looking at
 * the wrong set of methods, or at one that fails to load.
 */
class Locale
{
    /** Briqpay's default when nothing usable can be derived. */
    public const FALLBACK = 'en-gb';

    /**
     * Region to assume for a bare language code.
     *
     * PrestaShop installs frequently carry a language with no region at all
     * ("en", "sv"), which Briqpay will not accept on its own.
     */
    public const DEFAULT_REGIONS = [
        'sv' => 'sv-se',
        'nb' => 'nb-no',
        'nn' => 'nb-no',
        'no' => 'nb-no',
        'da' => 'da-dk',
        'fi' => 'fi-fi',
        'is' => 'is-is',
        'de' => 'de-de',
        'nl' => 'nl-nl',
        'fr' => 'fr-fr',
        'es' => 'es-es',
        'it' => 'it-it',
        'pt' => 'pt-pt',
        'pl' => 'pl-pl',
        'et' => 'et-ee',
        'lv' => 'lv-lv',
        'lt' => 'lt-lt',
        'cs' => 'cs-cz',
        'sk' => 'sk-sk',
        'hu' => 'hu-hu',
        'ro' => 'ro-ro',
        'bg' => 'bg-bg',
        'el' => 'el-gr',
        'ga' => 'en-ie',
    ];

    /**
     * Normalise any PrestaShop locale or language code.
     *
     * @param string $locale For example "en-US", "sv_SE", "sv" or "".
     *
     * @return string
     */
    public static function normalise($locale)
    {
        $value = strtolower(str_replace('_', '-', trim((string) $locale)));
        $value = (string) preg_replace('/[^a-z-]/', '', $value);

        $parts = array_values(array_filter(explode('-', $value), function ($part) {
            return $part !== '';
        }));

        if (empty($parts)) {
            return self::FALLBACK;
        }

        $language = $parts[0];

        // Briqpay treats every English variant as en-gb; en-us in particular is
        // not a locale it recognises.
        if ($language === 'en') {
            return self::FALLBACK;
        }

        if (isset($parts[1]) && strlen($parts[1]) === 2) {
            return $language . '-' . $parts[1];
        }

        return isset(self::DEFAULT_REGIONS[$language])
            ? self::DEFAULT_REGIONS[$language]
            : self::FALLBACK;
    }

    /**
     * Normalise the locale of a PrestaShop language.
     *
     * Language::$locale only exists from PrestaShop 1.7.6, so fall back to the
     * iso code on older installs.
     *
     * @param \Language $language
     *
     * @return string
     */
    public static function fromLanguage(\Language $language)
    {
        if (!empty($language->locale)) {
            return self::normalise($language->locale);
        }

        if (!empty($language->language_code)) {
            return self::normalise($language->language_code);
        }

        return self::normalise(isset($language->iso_code) ? $language->iso_code : '');
    }
}
