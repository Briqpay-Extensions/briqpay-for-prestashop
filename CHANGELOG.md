# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] — 2026-09-11

A rewrite. Checkout moves into PrestaShop's native flow, order management gains
the operations it was missing, and the module picks up a test suite and CI.

Upgrading is automatic — see *Upgrading from 1.x* in the README for the three
behaviour changes worth knowing about.

### Added

- **Cancel operation.** Release an uncaptured authorisation, from the order
  panel or by moving the order to Cancelled.
- **Partial capture and refund.** Enter an amount on the order panel, or issue a
  PrestaShop credit slip and the matching partial refund is sent to Briqpay.
- **`refund_status` webhook.** Refunds made in the Briqpay dashboard now reach
  PrestaShop. Previously only `order_status` and `capture_status` were
  subscribed.
- **Off-site order recovery.** If a shopper completes payment but never returns
  to the shop, the `order_status` webhook creates the order.
- **Briqpay payment panel** on the order page, with capture / refund / cancel
  controls and running captured and refunded totals.
- **Hosted payment pages.** Generate a Briqpay payment link from the order
  page for orders taken by phone or email, in any of three flows (consumer,
  business payment-only, business full checkout). Payment-only flows pre-fill
  the order's address; the full checkout flow collects it on the page. The
  page title, logo and cart visibility are configurable. Regenerating a link
  is blocked once the order is paid or captured, so a customer cannot be
  charged twice for the same order.
- **Configurable terms URL**, defaulting to the shop's own conditions page.
- **Connection test** on the configuration screen.
- **Toggleable logging** with three levels, and automatic redaction of secrets,
  tokens, emails, phone numbers and VAT numbers.
- **Automatic B2C/B2B detection** from the company field on the billing address.
- **"Awaiting Briqpay payment" order state**, created on install.
- **Concurrency locking** so a webhook and a returning shopper cannot create the
  same order twice.
- **Twelve `actionBriqpay*` hooks** for merchant customisation; see the README.
- **190 unit tests** running on plain PHP with no PrestaShop install.
- **GitHub Actions CI**: syntax checks on PHP 7.2–8.3, tests on 7.4–8.3,
  PHPStan, php-cs-fixer, module structure validation and a packaged zip.
- **Docker Compose stack** with PrestaShop 1.7.8, 8.1 and 9.0 profiles.
- **Dual-remote release process.** Development stays on Bitbucket; pushing
  `main` to GitHub tags the module version and publishes a release. See
  `RELEASING.md` and `scripts/publish.sh`.

### Changed

- **Checkout is embedded in PrestaShop's native payment step** instead of
  replacing the whole cart page. In takeover mode the native radio list, option
  label, logo and *Place order* button are all suppressed, so the Briqpay iframe
  is the entire payment step.
- **Auto-capturing payment methods are recognised at order time.** Briqpay
  reports `autoCaptureEnabled` on the transaction, and a session can already
  carry its capture when the order is created. Both are now read from the
  session instead of waiting for the capture webhook, which left a window of
  minutes where the order page reported nothing captured and offered a Capture
  button for a payment that was settling itself.
- **A manual capture is no longer recorded as automatic.** The capture webhook
  hardcoded the auto-captured flag, so a capture the merchant made by hand was
  reported on the order page as Briqpay's doing.
- **The order panel loads its own stylesheet and script.** PrestaShop 8 renders
  the order page with Symfony, where the legacy controller's `addCSS()` and
  `addJS()` are silently ignored -- the panel arrived completely unstyled.
- **Locales are normalised to the form Briqpay accepts.** PrestaShop stores
  BCP-47 with an uppercase region and ships English as `en-US`; Briqpay wants a
  lowercase pair and uses `en-gb` for English. The locale is part of how Briqpay
  decides which payment methods to present, so an unrecognised one leaves the
  shopper with the wrong set. Bare language codes gain a default region.
- **The configuration page warns about currencies the module is not enabled
  for.** PrestaShop records these at install time and does not backfill, so a
  currency added later silently hides the module at checkout.
- **Decision rejections reach Briqpay.** A reject requires `rejectionType` at
  the top level of the request; the API reports its absence as
  "data.rejectionType is required", and nesting it under `data` is silently
  ignored, so every rejection failed with a 400. The shop believed it had
  stopped a purchase Briqpay never heard about. Rejections use `notify_user`,
  which shows the reason and lets the shopper correct it and retry.
- **The decision step is requested in the session payload**
  (`modules.config.payment.decision.enabled`), so Briqpay pauses before taking
  money and the shop can allow or reject. It is not enabled by default: without
  requesting it, `make_decision` never fires and the terms and cart-total checks
  never run at all. Requested only when at least one validation is switched on. The `CartController` override, the standalone
  checkout controller, its address form and its shipping selector are removed;
  PrestaShop collects addresses and shipping as it does for any other payment
  method.
- **Session state moved from the shopper's cookie to the database.** A cleared
  cookie no longer orphans a live payment session, and webhooks — which arrive
  without a browser session — can find the cart.
- **Sessions are only updated when the cart has actually changed**, compared via
  a fingerprint, rather than on every cart event.
- **Captures and refunds are built from the order, not the live cart**, so a
  shopper changing their cart after checking out can no longer alter what is
  captured.
- **Order state changes go through `OrderHistory`**, so stock, invoices and
  customer emails behave as they do for every other payment method.
- The B2C/B2B switcher is replaced by automatic detection, gated on
  PrestaShop's own B2B mode. A VAT number alone no longer implies a business
  checkout: that field is free text and a mistyped postcode in it used to
  send ordinary shoppers into a company-lookup flow.
- Source is reorganised into a PSR-4 `src/` tree with a service container.
- The admin configuration screen is redesigned.

### Fixed

- **Capture and refund never ran.** The module registered
  `actionOrderStatusUpdate` but implemented `hookUpdateOrderStatus` — PrestaShop
  calls neither for a committed state change. Now `actionOrderStatusPostUpdate`.
- **Order states were hardcoded numeric ids** (2, 3, 5, 6, 7). Any shop that had
  reordered or localised its statuses had orders moved to the wrong state. All
  state ids now resolve through `Configuration`.
- **Line prices were truncated, not rounded.** `(int) (12.35 * 100)` is 1234 in
  IEEE-754, so line items were under-reported by a minor unit and Briqpay
  rejected the session.
- **Division by zero** on free shipping and fully discounted cart rules, which
  produced a `NAN` tax rate.
- **Rounding drift between line items and cart totals** now produces a single
  adjustment line instead of a rejected session.
- **The shopper's email was read from `Address::$other`**, PrestaShop's
  free-text note field. It comes from the customer record now.
- **`customerType` was undefined** when no override was passed, so sessions were
  created with a null customer type.
- **`BriqpayUpdateSession::update()` returned nothing** while callers assigned
  its result.
- **`catch (execption $e)`** — a misspelled class name, so those blocks caught
  nothing and the intended fallback never ran.
- **Fatal error in the capture webhook** when the cart had no order: `$order`
  was used outside the block that defined it.
- **Company details were only sent on session update**, so a session created for
  a business buyer started out without them.
- **Company data is never attached to a consumer session.** Briqpay cannot
  reconcile the two and the checkout stalls on "Waiting for address details"
  with no payment methods offered -- a stray VAT number on the billing
  address was enough to trigger it.
- **JWT payloads were decoded with plain `base64_decode`**, mangling the
  base64url alphabet and missing padding.
- **Address comparison at the decision step was case- and whitespace-sensitive**,
  rejecting legitimate purchases over `"Storgatan  1"` vs `"Storgatan 1"`.
- **A hardcoded `https://terms.com`** was sent as the terms URL — a third-party
  domain.
- **`pSQL()` was used to escape template output**, which is a SQL escaper, not
  an HTML one. Templates escape properly now.
- **The module table had no primary key or indexes.**
- **Missing `index.php` guards** in module directories.
- Class name typo: `BriqpayUpdateRefence` → the reference update now lives on
  `SessionManager`.

### Security

- POST bodies on the webhook endpoint are no longer acted on directly. The
  handler reads only the session id from the request and re-reads authoritative
  state from Briqpay over the authenticated API.
- The decision endpoint verifies that the submitted session id is the one the
  shop opened for the current cart, rather than accepting any id from the
  browser.
- Repository writes go through a column whitelist.
- TLS peer and host verification are enabled explicitly on all API calls.

## [1.0.0] — 2025-09-29

Initial release.
