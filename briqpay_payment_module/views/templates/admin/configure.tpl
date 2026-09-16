{*
* Copyright since 2020 Briqpay AB
*
* @author    Briqpay AB <hello@briqpay.com>
* @copyright Since 2020 Briqpay AB
* @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
*}

<div class="briqpay-admin"
     id="briqpay-admin"
     data-briqpay-secret-preview="{$briqpaySecretPreview|escape:'html':'UTF-8'}">

  <header class="briqpay-hero">
    <div class="briqpay-hero__identity">
      <img class="briqpay-hero__logo" src="{$briqpayLogoUri|escape:'html':'UTF-8'}" alt="Briqpay" />
      <div>
        <h2 class="briqpay-hero__title">Briqpay Payments</h2>
        <p class="briqpay-hero__subtitle">
          {l s='One integration for invoice, card, direct bank and BNPL payments.' mod='briqpay_payment_module'}
        </p>
      </div>
    </div>

    <div class="briqpay-hero__meta">
      <span class="briqpay-badge briqpay-badge--{if $briqpayIsLive}live{else}test{/if}">
        {if $briqpayIsLive}
          {l s='Live mode' mod='briqpay_payment_module'}
        {else}
          {l s='Test mode' mod='briqpay_payment_module'}
        {/if}
      </span>
      <span class="briqpay-badge briqpay-badge--version">v{$briqpayModuleVersion|escape:'html':'UTF-8'}</span>
    </div>
  </header>

  {if $briqpayMissingCurrencies}
    <div class="briqpay-callout briqpay-callout--warning">
      <strong>{l s='Not offered in every currency.' mod='briqpay_payment_module'}</strong>
      {l s='Briqpay is not enabled for:' mod='briqpay_payment_module'}
      <strong>{','|implode:$briqpayMissingCurrencies|escape:'html':'UTF-8'}</strong>.
      {l s='Shoppers using those currencies will not see it at checkout. Enable it under Payment > Preferences.' mod='briqpay_payment_module'}
    </div>
  {/if}

  {if !$briqpayIsConfigured}
    <div class="briqpay-callout briqpay-callout--warning">
      <strong>{l s='Almost there.' mod='briqpay_payment_module'}</strong>
      {l s='Add your merchant ID and shared secret below to start accepting payments.' mod='briqpay_payment_module'}
    </div>
  {/if}

  <section class="briqpay-cards">

    <article class="briqpay-card">
      <h3 class="briqpay-card__title">{l s='Webhook endpoint' mod='briqpay_payment_module'}</h3>
      <p class="briqpay-card__body">
        {l s='Briqpay sends order, capture and refund notifications here. It is registered automatically on every session the module opens.' mod='briqpay_payment_module'}
      </p>
      <div class="briqpay-copyfield">
        <input type="text"
               readonly
               class="briqpay-copyfield__input"
               id="briqpay-webhook-url"
               value="{$briqpayWebhookUrl|escape:'html':'UTF-8'}" />
        <button type="button"
                class="btn btn-default briqpay-copyfield__button"
                data-briqpay-copy="briqpay-webhook-url">
          {l s='Copy' mod='briqpay_payment_module'}
        </button>
      </div>
      <p class="briqpay-card__hint">
        {l s='The endpoint must be reachable from the public internet over HTTPS.' mod='briqpay_payment_module'}
      </p>
    </article>

    <article class="briqpay-card">
      <h3 class="briqpay-card__title">{l s='Connection' mod='briqpay_payment_module'}</h3>
      <p class="briqpay-card__body">
        {l s='Verify that the saved credentials are accepted by the selected environment.' mod='briqpay_payment_module'}
      </p>
      <button type="submit"
              name="submitBriqpayTestConnection"
              value="1"
              class="btn btn-primary briqpay-card__action"
              form="module_form">
        {l s='Test connection' mod='briqpay_payment_module'}
      </button>
      <p class="briqpay-card__hint">
        {l s='Credentials differ between the playground and production environments.' mod='briqpay_payment_module'}
      </p>
    </article>

    <article class="briqpay-card">
      <h3 class="briqpay-card__title">{l s='Documentation' mod='briqpay_payment_module'}</h3>
      <p class="briqpay-card__body">
        {l s='Setup guide, supported features and payment method availability by market.' mod='briqpay_payment_module'}
      </p>
      <a href="{$briqpayDocsUrl|escape:'html':'UTF-8'}"
         target="_blank"
         rel="noopener noreferrer"
         class="btn btn-default briqpay-card__action">
        {l s='Open the PrestaShop guide' mod='briqpay_payment_module'}
      </a>
      <p class="briqpay-card__hint">
        {l s='Support' mod='briqpay_payment_module'}:
        <a href="mailto:{$briqpaySupportEmail|escape:'html':'UTF-8'}?subject=PrestaShop%20module%20{$briqpayModuleVersion|escape:'url'}">
          {$briqpaySupportEmail|escape:'html':'UTF-8'}
        </a>
      </p>
    </article>

  </section>
</div>
