{*
* Copyright since 2020 Briqpay AB
*
* @author    Briqpay AB <hello@briqpay.com>
* @copyright Since 2020 Briqpay AB
* @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
*}

{**
 * Rendered as the Briqpay payment option's form, so the iframe appears inside
 * PrestaShop's native payment step rather than on a page of its own.
 *
 * The <form> wrapper is mandatory, not decoration. Everything passed to
 * PaymentOption::setForm() goes through PaymentOptionFormDecorator, which does:
 *
 *     $forms = $doc->getElementsByTagName('form');
 *     if ($forms->length !== 1) { return false; }
 *
 * so markup with no form element -- or more than one -- is silently discarded
 * and the payment option renders with an empty body. Exactly one form element
 * must be present, and the Briqpay snippet itself must not contain another.
 *
 * PrestaShop appends its own hidden submit button to this form and clicks it
 * when the shopper presses "Place order". Briqpay completes the purchase inside
 * the iframe instead, so briqpay_checkout.js cancels that submit.
 *}
<form class="briqpay-checkout-form"
      id="briqpay-checkout-form"
      method="post"
      action="{$briqpayFormAction|escape:'html':'UTF-8'}">

    <div class="briqpay-checkout"
         id="briqpay-checkout"
         data-briqpay-session="{$briqpaySessionId|escape:'html':'UTF-8'}"
         {if $briqpayTakeover}data-briqpay-takeover="1"{/if}>

        <div class="briqpay-checkout__notice" id="briqpay-checkout-notice" role="alert" hidden></div>

        <div class="briqpay-checkout__frame" id="briqpay-checkout-frame">
            {$briqpaySnippet nofilter}
        </div>
    </div>
</form>
