<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Typed accessors for every Briqpay configuration key.
 *
 * Every read of the PrestaShop Configuration table goes through here so that
 * defaults live in exactly one place and the rest of the module never has to
 * guess what a raw Configuration::get() string means.
 */
class Settings
{
    public const LIVE_MODE = 'BRIQPAY_LIVE_MODE';
    public const MERCHANT_ID = 'BRIQPAY_MID';
    public const SECRET = 'BRIQPAY_SECRET';
    public const CUSTOMER_TYPE = 'BRIQPAY_CUSTOMER_TYPE';
    public const TERMS_URL = 'BRIQPAY_TERMS_URL';
    public const WEBHOOK_BASE_URL = 'BRIQPAY_WEBHOOK_BASE_URL';
    public const TAKEOVER_PAYMENT_STEP = 'BRIQPAY_TAKEOVER_PAYMENT_STEP';
    public const ENFORCE_TERMS = 'BRIQPAY_ENFORCE_TERMS';
    public const AUTO_CAPTURE_STATE = 'BRIQPAY_AUTO_UPDATE_DELIVERED';
    public const CAPTURE_ON_STATE = 'BRIQPAY_CAPTURE_ON_STATE';
    public const REFUND_ON_STATE = 'BRIQPAY_REFUND_ON_STATE';
    public const CANCEL_ON_STATE = 'BRIQPAY_CANCEL_ON_STATE';
    public const DECISION_AMOUNT_CHECK = 'BRIQPAY_DECISION_AMOUNT_CHECK';
    public const DECISION_ADDRESS_CHECK = 'BRIQPAY_DECISION_ADDRESS_CHECK';
    public const LOGGING_ENABLED = 'BRIQPAY_LOGGING_ENABLED';
    public const LOG_LEVEL = 'BRIQPAY_LOG_LEVEL';
    public const DISPLAY_NAME = 'BRIQPAY_DISPLAY_NAME';
    public const HPP_ENABLED = 'BRIQPAY_HPP_ENABLED';
    public const HPP_DEFAULT_FLOW = 'BRIQPAY_HPP_DEFAULT_FLOW';
    public const HPP_PAGE_TITLE = 'BRIQPAY_HPP_PAGE_TITLE';
    public const HPP_LOGO_URL = 'BRIQPAY_HPP_LOGO_URL';
    public const HPP_SHOW_CART = 'BRIQPAY_HPP_SHOW_CART';

    public const CUSTOMER_TYPE_CONSUMER = 'consumer';
    public const CUSTOMER_TYPE_BUSINESS = 'business';
    public const CUSTOMER_TYPE_AUTO = 'auto';

    public const LIVE_BASE_URL = 'https://api.briqpay.com';
    public const TEST_BASE_URL = 'https://playground-api.briqpay.com';
    public const DASHBOARD_URL = 'https://app.briqpay.com/dashboard/sessions/orders/';

    /** Stands in for a stored secret in the admin, revealing nothing about it. */
    public const SECRET_MASK = '••••••••••••••••';

    /**
     * Defaults applied on install and used as fallbacks on every read.
     *
     * @return array<string, mixed>
     */
    public static function defaults()
    {
        return [
            self::LIVE_MODE => false,
            self::MERCHANT_ID => '',
            self::SECRET => '',
            self::CUSTOMER_TYPE => self::CUSTOMER_TYPE_AUTO,
            self::TERMS_URL => '',
            self::WEBHOOK_BASE_URL => '',
            self::TAKEOVER_PAYMENT_STEP => true,
            self::ENFORCE_TERMS => true,
            self::AUTO_CAPTURE_STATE => false,
            self::CAPTURE_ON_STATE => true,
            self::REFUND_ON_STATE => true,
            self::CANCEL_ON_STATE => true,
            self::DECISION_AMOUNT_CHECK => true,
            self::DECISION_ADDRESS_CHECK => false,
            self::LOGGING_ENABLED => true,
            self::LOG_LEVEL => 'error',
            self::DISPLAY_NAME => '',
            self::HPP_ENABLED => false,
            self::HPP_DEFAULT_FLOW => 'b2c',
            self::HPP_PAGE_TITLE => '',
            self::HPP_LOGO_URL => '',
            self::HPP_SHOW_CART => true,
        ];
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public static function get($key)
    {
        $defaults = self::defaults();
        $default = isset($defaults[$key]) ? $defaults[$key] : null;

        $value = \Configuration::get($key);

        // Configuration::get() returns false both for "missing" and for a
        // genuinely falsy stored value, so only fall back when nothing is set.
        if ($value === false && !\Configuration::hasKey($key)) {
            return $default;
        }

        return $value;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public static function getBool($key)
    {
        return (bool) self::get($key);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public static function getString($key)
    {
        $value = self::get($key);

        return $value === null || $value === false ? '' : (string) $value;
    }

    /**
     * @return bool
     */
    public static function isLiveMode()
    {
        return self::getBool(self::LIVE_MODE);
    }

    /**
     * Base URL of the Briqpay API for the configured environment.
     *
     * @return string
     */
    public static function getApiBaseUrl()
    {
        return self::isLiveMode() ? self::LIVE_BASE_URL : self::TEST_BASE_URL;
    }

    /**
     * Briqpay back office "test" flag: 1 for playground, 0 for production.
     *
     * @return int
     */
    public static function getBackOfficeMode()
    {
        return self::isLiveMode() ? 0 : 1;
    }

    /**
     * @return bool
     */
    public static function isConfigured()
    {
        return self::getString(self::MERCHANT_ID) !== '' && self::getString(self::SECRET) !== '';
    }

    /**
     * A masked stand-in for the stored secret.
     *
     * PrestaShop's HelperForm hardcodes value="" on every password input, so a
     * saved secret always renders as an empty box -- which looks exactly like
     * having lost it. This gives the field something to show.
     *
     * No part of the secret is included: the mask is a fixed string, so it
     * reveals neither the value nor its length.
     *
     * @return string Empty when nothing is stored.
     */
    public static function getSecretPreview()
    {
        return self::getString(self::SECRET) !== '' ? self::SECRET_MASK : '';
    }

    /**
     * Terms URL shown inside the Briqpay iframe. Falls back to the shop's own
     * CMS conditions page when the merchant has not overridden it.
     *
     * @param \Context $context
     *
     * @return string
     */
    public static function getTermsUrl(\Context $context)
    {
        $configured = trim(self::getString(self::TERMS_URL));
        if ($configured !== '') {
            return $configured;
        }

        $cmsId = (int) \Configuration::get('PS_CONDITIONS_CMS_ID');
        if ($cmsId > 0) {
            $link = $context->link->getCMSLink($cmsId, null, null, $context->language->id);
            if ($link) {
                return $link;
            }
        }

        return $context->link->getBaseLink();
    }

    /**
     * Rewrite a shop URL onto the configured public callback host.
     *
     * Briqpay calls webhooks from the internet, so that one URL has to be
     * reachable from outside -- unlike the redirect and terms URLs, which the
     * buyer's own browser follows and which are therefore fine on whatever
     * hostname the shop normally answers on.
     *
     * Separating the two matters wherever the storefront host is not the host
     * Briqpay can reach: a shop behind a reverse proxy or CDN that terminates
     * on a different name, and local development, where the shop lives on
     * localhost and only the tunnel is public. Without it the only way to
     * receive a webhook is to move the whole shop onto the public hostname,
     * which rewrites the shop URL, the SSL flags and the rewrite rules in
     * .htaccess -- and breaks every one of them again as soon as that hostname
     * changes.
     *
     * @param string $url A URL already built from the shop's own domain.
     *
     * @return string
     */
    public static function toPublicUrl($url)
    {
        $base = trim(self::getString(self::WEBHOOK_BASE_URL));

        if ($base === '') {
            return $url;
        }

        $override = parse_url($base);
        if (empty($override['host'])) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $scheme = !empty($override['scheme']) ? $override['scheme'] : 'https';
        $authority = $override['host'] . (isset($override['port']) ? ':' . $override['port'] : '');

        // Only the scheme and authority are replaced: the path identifies the
        // controller and must survive intact.
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        // A base given with a path prefix ("https://host/shop") keeps it.
        $prefix = isset($override['path']) ? rtrim($override['path'], '/') : '';

        return $scheme . '://' . $authority . $prefix . $path . $query;
    }

    /**
     * Resolve which Briqpay customer type a given cart should run as.
     *
     * "auto" returns business when, and only when, the buyer typed a company
     * name on the invoice address.
     *
     * The company field is part of PrestaShop's standard address form and is
     * not tied to its B2B mode, so filling it in is a deliberate act by the
     * buyer and the one signal worth trusting. A VAT number is not: that field
     * is free text and routinely holds something else entirely -- a postcode
     * typed one box too low is enough to send an ordinary shopper into a
     * company-lookup flow they never asked for.
     *
     * A merchant who wants every checkout to run one way can still say so
     * outright in the module settings, which takes precedence over this.
     *
     * @param \Cart $cart
     *
     * @return string
     */
    public static function resolveCustomerType(\Cart $cart)
    {
        $configured = self::getString(self::CUSTOMER_TYPE);

        if ($configured === self::CUSTOMER_TYPE_CONSUMER || $configured === self::CUSTOMER_TYPE_BUSINESS) {
            return $configured;
        }

        $addressId = (int) $cart->id_address_invoice;
        if ($addressId > 0) {
            $address = new \Address($addressId);

            if (trim((string) $address->company) !== '') {
                return self::CUSTOMER_TYPE_BUSINESS;
            }
        }

        return self::CUSTOMER_TYPE_CONSUMER;
    }
}
