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
 * Maps PrestaShop addresses onto Briqpay's billing/shipping/company objects.
 */
class AddressBuilder
{
    /**
     * @param \Cart $cart
     *
     * @return array|null
     */
    public function buildBilling(\Cart $cart)
    {
        return $this->buildAddress((int) $cart->id_address_invoice, $this->resolveEmail($cart));
    }

    /**
     * @param \Cart $cart
     *
     * @return array|null
     */
    public function buildShipping(\Cart $cart)
    {
        return $this->buildAddress((int) $cart->id_address_delivery, $this->resolveEmail($cart));
    }

    /**
     * Company details, taken from the invoice address when the buyer filled
     * them in.
     *
     * Only ever call this for a business session. Attaching a company block to
     * a consumer session leaves Briqpay with a contradiction it cannot resolve:
     * the checkout stalls on "Waiting for address details" and offers no
     * payment methods at all. The VAT field in particular is free text and
     * routinely holds something else, so its presence is not evidence that this
     * is a business purchase -- see Settings::resolveCustomerType(), and
     * resolveCin() below for why it is not sent as the registration number
     * either.
     *
     * @param \Cart $cart
     *
     * @return array|null
     */
    public function buildCompany(\Cart $cart)
    {
        $addressId = (int) $cart->id_address_invoice;
        if ($addressId <= 0) {
            return null;
        }

        $address = new \Address($addressId);
        $name = trim((string) $address->company);
        $cin = $this->resolveCin($cart);

        if ($name === '' && $cin === '') {
            return null;
        }

        $company = [];
        if ($name !== '') {
            $company['name'] = $name;
        }
        if ($cin !== '') {
            $company['cin'] = $cin;
        }

        return $company;
    }

    /**
     * The buyer's company registration number, if the shop actually holds one.
     *
     * Deliberately NOT the address's VAT field. That field is free text, holds a
     * VAT number at best and something else entirely in practice -- a postcode
     * typed one box too low is enough -- and Briqpay does not merely ignore a
     * CIN it cannot validate: it discards the whole company block. The session
     * then carries customerType "business" with no company at all, the payment
     * module never leaves `not_yet_reached`, and the shopper is left looking at
     * an empty iframe with no payment methods and nothing to click.
     *
     * Losing the number is survivable; losing the company name is not. So the
     * number is only sent when it comes from a field that is meant to hold one
     * and looks like one.
     *
     * @param \Cart $cart
     *
     * @return string Empty when the shop has nothing worth sending.
     */
    private function resolveCin(\Cart $cart)
    {
        $customerId = (int) $cart->id_customer;
        if ($customerId <= 0) {
            return '';
        }

        // siret is PrestaShop's own company registration number, and the only
        // field it offers for one.
        $customer = new \Customer($customerId);
        $siret = trim((string) $customer->siret);

        return self::looksLikeCompanyNumber($siret) ? $siret : '';
    }

    /**
     * Whether a value is plausibly a company registration number.
     *
     * Formats differ per country -- 10 digits in Sweden, 9 in Norway, 8 in
     * Denmark, 14 for a French SIRET -- so this only rules out values that
     * cannot be one anywhere Briqpay operates, rather than validating any
     * particular country's format.
     *
     * @param string $value
     *
     * @return bool
     */
    public static function looksLikeCompanyNumber($value)
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return strlen($digits) >= 8;
    }

    /**
     * @param int    $addressId
     * @param string $email
     *
     * @return array|null
     */
    private function buildAddress($addressId, $email)
    {
        if ($addressId <= 0) {
            return null;
        }

        $address = new \Address($addressId);
        if (!\Validate::isLoadedObject($address)) {
            return null;
        }

        return [
            'streetAddress' => (string) $address->address1,
            'streetAddress2' => (string) $address->address2,
            'zip' => (string) $address->postcode,
            'city' => (string) $address->city,
            'region' => $address->id_state ? (string) \State::getNameById((int) $address->id_state) : '',
            'firstName' => (string) $address->firstname,
            'lastName' => (string) $address->lastname,
            'email' => $email,
            'phoneNumber' => $this->resolvePhone($address),
            'country' => (string) \Country::getIsoById((int) $address->id_country),
        ];
    }

    /**
     * The buyer's email lives on the Customer, not the Address. The previous
     * module read Address::$other, which is a free-text note field.
     *
     * @param \Cart $cart
     *
     * @return string
     */
    private function resolveEmail(\Cart $cart)
    {
        $customerId = (int) $cart->id_customer;
        if ($customerId <= 0) {
            return '';
        }

        $customer = new \Customer($customerId);

        return \Validate::isLoadedObject($customer) ? (string) $customer->email : '';
    }

    /**
     * @param \Address $address
     *
     * @return string
     */
    private function resolvePhone(\Address $address)
    {
        $phone = trim((string) $address->phone);

        return $phone !== '' ? $phone : trim((string) $address->phone_mobile);
    }
}
