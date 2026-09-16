{*
* Copyright since 2020 Briqpay AB
*
* @author    Briqpay AB <hello@briqpay.com>
* @copyright Since 2020 Briqpay AB
* @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
*}

{**
 * Briqpay payment panel on the order detail page, with capture, refund and
 * cancel actions.
 *}
{* Loaded here rather than through the controller: PrestaShop 8 renders this
   page with Symfony, where addCSS()/addJS() on the legacy controller are
   ignored and the panel arrives unstyled. *}
<link rel="stylesheet" href="{$briqpayCssUri|escape:'html':'UTF-8'}">

<div class="card mt-2 briqpay-admin briqpay-order-panel" id="briqpay-order-panel">

  <div class="card-header briqpay-order-panel__header">
    <div class="briqpay-order-panel__heading">
      <img class="briqpay-order-panel__logo" src="{$briqpayLogoUri|escape:'html':'UTF-8'}" alt="Briqpay" />
      <h3 class="card-header-title">{l s='Briqpay payment' mod='briqpay_payment_module'}</h3>
    </div>
    <span class="briqpay-badge briqpay-badge--{if $briqpayIsLive}live{else}test{/if}">
      {if $briqpayIsLive}
        {l s='Live' mod='briqpay_payment_module'}
      {else}
        {l s='Test' mod='briqpay_payment_module'}
      {/if}
    </span>
  </div>

  <div class="card-body">

    {if $briqpayFlash}
      <div class="briqpay-callout briqpay-callout--{if $briqpayFlash.type == 'error'}warning{else}success{/if}">
        {$briqpayFlash.message|escape:'html':'UTF-8'}
      </div>
    {/if}

    {if $briqpayHasPayment}
    <div class="briqpay-summary">
      <div class="briqpay-summary__item">
        <span class="briqpay-summary__label">{l s='Order total' mod='briqpay_payment_module'}</span>
        <span class="briqpay-summary__value">{$briqpayTotals.total nofilter}</span>
      </div>
      <div class="briqpay-summary__item">
        <span class="briqpay-summary__label">{l s='Captured' mod='briqpay_payment_module'}</span>
        <span class="briqpay-summary__value briqpay-summary__value--captured">{$briqpayTotals.captured nofilter}</span>
      </div>
      <div class="briqpay-summary__item">
        <span class="briqpay-summary__label">{l s='Refunded' mod='briqpay_payment_module'}</span>
        <span class="briqpay-summary__value briqpay-summary__value--refunded">{$briqpayTotals.refunded nofilter}</span>
      </div>
      <div class="briqpay-summary__item">
        <span class="briqpay-summary__label">{l s='Status' mod='briqpay_payment_module'}</span>
        <span class="briqpay-summary__value">
          <span class="briqpay-status briqpay-status--{$briqpay.status|escape:'html':'UTF-8'}">
            {$briqpay.status|escape:'html':'UTF-8'}
          </span>
        </span>
      </div>
    </div>

    <dl class="briqpay-details">
      <div class="briqpay-details__row">
        <dt>{l s='Session' mod='briqpay_payment_module'}</dt>
        <dd>
          <a href="{$briqpayDashboardLink|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
            {$briqpay.session_id|escape:'html':'UTF-8'}
          </a>
        </dd>
      </div>
      <div class="briqpay-details__row">
        <dt>{l s='Payment method' mod='briqpay_payment_module'}</dt>
        <dd>{if $briqpay.psp_display_name}{$briqpay.psp_display_name|escape:'html':'UTF-8'}{else}&mdash;{/if}</dd>
      </div>
      <div class="briqpay-details__row">
        <dt>{l s='Reservation' mod='briqpay_payment_module'}</dt>
        <dd>{if $briqpay.reservation_id}{$briqpay.reservation_id|escape:'html':'UTF-8'}{else}&mdash;{/if}</dd>
      </div>
      <div class="briqpay-details__row">
        <dt>{l s='Customer type' mod='briqpay_payment_module'}</dt>
        <dd>{if $briqpay.customer_type}{$briqpay.customer_type|escape:'html':'UTF-8'}{else}&mdash;{/if}</dd>
      </div>
      {if $briqpay.company_name}
        <div class="briqpay-details__row">
          <dt>{l s='Company' mod='briqpay_payment_module'}</dt>
          <dd>
            {$briqpay.company_name|escape:'html':'UTF-8'}
            {if $briqpay.company_cin}
              <span class="briqpay-details__muted">({$briqpay.company_cin|escape:'html':'UTF-8'})</span>
            {/if}
          </dd>
        </div>
      {/if}
      <div class="briqpay-details__row">
        <dt>{l s='Auto-captured' mod='briqpay_payment_module'}</dt>
        <dd>
          {if $briqpay.auto_captured}
            {l s='Yes' mod='briqpay_payment_module'}
          {else}
            {l s='No' mod='briqpay_payment_module'}
          {/if}
        </dd>
      </div>
    </dl>
    {/if}

    {* Storefront orders can never take a payment link, so the section would be
       nothing but a refusal on the majority of orders. Show it only when there
       is a link to hand out or one can still be created. *}
    {if $briqpayHppEnabled && ($briqpay.hpp_url || !$briqpayHppBlockReason)}
      <section class="briqpay-hpp">
        <h4 class="briqpay-hpp__title">{l s='Payment link' mod='briqpay_payment_module'}</h4>

        {if $briqpay.hpp_url}
          <p class="briqpay-card__hint">
            {l s='Created' mod='briqpay_payment_module'} {$briqpay.hpp_created|escape:'html':'UTF-8'}
            &middot; {$briqpay.hpp_flow|escape:'html':'UTF-8'}
          </p>
          <div class="briqpay-copyfield">
            <input type="text"
                   readonly
                   class="briqpay-copyfield__input"
                   id="briqpay-hpp-url"
                   value="{$briqpay.hpp_url|escape:'html':'UTF-8'}" />
            <button type="button"
                    class="btn btn-default briqpay-copyfield__button"
                    data-briqpay-copy="briqpay-hpp-url">
              {l s='Copy' mod='briqpay_payment_module'}
            </button>
            <a href="{$briqpay.hpp_url|escape:'html':'UTF-8'}"
               target="_blank"
               rel="noopener noreferrer"
               class="btn btn-default briqpay-copyfield__button">
              {l s='Open' mod='briqpay_payment_module'}
            </a>
          </div>
        {else}
          <p class="briqpay-card__hint">
            {l s='Generate a link the customer can pay from, for an order taken by phone or email.' mod='briqpay_payment_module'}
          </p>
        {/if}

        {if $briqpayHppBlockReason}
          <p class="briqpay-details__muted">{$briqpayHppBlockReason|escape:'html':'UTF-8'}</p>
        {else}
          <form method="post"
                action="{$briqpayActionUrl|escape:'html':'UTF-8'}"
                class="briqpay-actions briqpay-actions--hpp">
            <input type="hidden" name="briqpay_action" value="1" />
            <input type="hidden" name="briqpay_order_id" value="{$briqpayOrderId|intval}" />
            <input type="hidden" name="briqpay_operation" value="create_link" />

            <div class="briqpay-actions__amount">
              <label for="briqpay-hpp-flow">{l s='Flow' mod='briqpay_payment_module'}</label>
              <select name="briqpay_hpp_flow" id="briqpay-hpp-flow" class="form-control">
                {foreach from=$briqpayHppFlows item=flow}
                  <option value="{$flow.id|escape:'html':'UTF-8'}"
                    {if $flow.id == $briqpayHppDefaultFlow}selected{/if}>
                    {$flow.name|escape:'html':'UTF-8'}
                  </option>
                {/foreach}
              </select>
            </div>

            <div class="briqpay-actions__buttons">
              <button type="submit"
                      class="btn btn-primary"
                      {if $briqpay.hpp_url}
                        data-briqpay-confirm="{l s='Create a new payment link? The existing link will stop working.' mod='briqpay_payment_module'}"
                      {/if}>
                {if $briqpay.hpp_url}
                  {l s='Regenerate link' mod='briqpay_payment_module'}
                {else}
                  {l s='Create payment link' mod='briqpay_payment_module'}
                {/if}
              </button>
            </div>
          </form>
        {/if}
      </section>
    {/if}

    {if $briqpayCanCapture || $briqpayCanRefund || $briqpayCanCancel}
      <form method="post"
            action="{$briqpayActionUrl|escape:'html':'UTF-8'}"
            class="briqpay-actions"
            id="briqpay-actions-form">
        <input type="hidden" name="briqpay_action" value="1" />
        <input type="hidden" name="briqpay_order_id" value="{$briqpayOrderId|intval}" />
        <input type="hidden" name="briqpay_operation" id="briqpay-operation" value="" />

        <div class="briqpay-actions__amount">
          <label for="briqpay-amount">
            {l s='Amount' mod='briqpay_payment_module'}
            <span class="briqpay-details__muted">({$briqpayCurrency|escape:'html':'UTF-8'})</span>
          </label>
          <input type="number"
                 step="0.01"
                 min="0"
                 class="form-control"
                 id="briqpay-amount"
                 name="briqpay_amount"
                 placeholder="{l s='Leave empty for the full amount' mod='briqpay_payment_module'}" />
        </div>

        <div class="briqpay-actions__buttons">
          {if $briqpayCanCapture}
            <button type="submit"
                    class="btn btn-primary"
                    data-briqpay-operation="capture"
                    data-briqpay-max="{$briqpayCapturableRaw|escape:'html':'UTF-8'}"
                    data-briqpay-confirm="{l s='Capture this payment?' mod='briqpay_payment_module'}">
              {l s='Capture' mod='briqpay_payment_module'}
              <span class="briqpay-actions__hint">{l s='up to' mod='briqpay_payment_module'} {$briqpayTotals.capturable nofilter}</span>
            </button>
          {/if}

          {if $briqpayCanRefund}
            <button type="submit"
                    class="btn btn-warning"
                    data-briqpay-operation="refund"
                    data-briqpay-max="{$briqpayRefundableRaw|escape:'html':'UTF-8'}"
                    data-briqpay-confirm="{l s='Refund this payment?' mod='briqpay_payment_module'}">
              {l s='Refund' mod='briqpay_payment_module'}
              <span class="briqpay-actions__hint">{l s='up to' mod='briqpay_payment_module'} {$briqpayTotals.refundable nofilter}</span>
            </button>
          {/if}

          {if $briqpayCanCancel}
            <button type="submit"
                    class="btn btn-default"
                    data-briqpay-operation="cancel"
                    data-briqpay-confirm="{l s='Cancel this authorisation? This cannot be undone.' mod='briqpay_payment_module'}">
              {l s='Cancel authorisation' mod='briqpay_payment_module'}
            </button>
          {/if}
        </div>
      </form>
    {elseif $briqpayCapturePending || $briqpayRefundPending}
      <p class="briqpay-details__muted briqpay-actions--empty">
        {if $briqpayCapturePending}
          {l s='A capture of %amount% is in progress. Briqpay will confirm it shortly; no action is needed.' sprintf=['%amount%' => $briqpayPendingLabel] mod='briqpay_payment_module'}
        {else}
          {l s='A refund of %amount% is in progress. Briqpay will confirm it shortly; no action is needed.' sprintf=['%amount%' => $briqpayPendingLabel] mod='briqpay_payment_module'}
        {/if}
      </p>
    {elseif $briqpayAwaitingApproval}
      <p class="briqpay-details__muted briqpay-actions--empty">
        {l s='This payment is still pending with Briqpay. Capture and cancel become available once it is approved.' mod='briqpay_payment_module'}
      </p>
    {elseif $briqpayAwaitingCapture}
      <p class="briqpay-details__muted briqpay-actions--empty">
        {l s='This payment method settles automatically. Briqpay will confirm the capture shortly; no action is needed.' mod='briqpay_payment_module'}
      </p>
    {elseif $briqpayPaymentClosed}
      <p class="briqpay-details__muted briqpay-actions--empty">
        {l s='This payment is %status% and cannot be captured, refunded or cancelled.' sprintf=['%status%' => $briqpayStatusLabel] mod='briqpay_payment_module'}
      </p>
    {elseif $briqpayHasPayment}
      <p class="briqpay-details__muted briqpay-actions--empty">
        {l s='This payment is fully settled. No further action is available.' mod='briqpay_payment_module'}
      </p>
    {/if}

  </div>
</div>

<script src="{$briqpayJsUri|escape:'html':'UTF-8'}"></script>
