# Briqpay for PrestaShop

Accept invoice, card, direct bank and BNPL payments in PrestaShop through a
single Briqpay integration.

<!-- Point these at the real repository once it exists; see RELEASING.md. -->
[![CI](https://github.com/Briqpay-Extensions/briqpay-for-prestashop/actions/workflows/ci.yml/badge.svg)](https://github.com/Briqpay-Extensions/briqpay-for-prestashop/actions/workflows/ci.yml)

## Requirements

| | |
| --- | --- |
| PrestaShop | 1.7.6 – 9.x |
| PHP | 7.2 – 8.3 |
| Extensions | `curl`, `json` |
| Account | A Briqpay merchant account ([sign up](https://briqpay.com)) |

## Installation

1. Download `briqpay_payment_module.zip` from the latest GitHub release.
2. Back office → **Modules → Module Manager → Upload a module**.
3. Open **Configure** and enter your merchant ID and shared secret.
4. Click **Test connection** to confirm the credentials are accepted.

Leave **Live mode** off while testing; the module then talks to Briqpay's
playground environment, which uses separate credentials.

## How checkout works

Briqpay renders as a normal PrestaShop payment option, so shoppers stay inside
the native checkout:

```
1 Personal information   PrestaShop
2 Addresses              PrestaShop
3 Shipping method        PrestaShop
4 Payment             →  Briqpay iframe
```

With **Replace the payment method list** enabled (the default), the iframe takes
over the payment step entirely: PrestaShop's radio list, the option label and
logo, and its *Place order* button are all suppressed, because the iframe
presents every method itself and the purchase completes inside it.

Nothing is hidden or disabled up front: the shopper sees every payment method
immediately. Validation happens at Briqpay's **decision step** — the pause
after the shopper presses pay but before any money moves:

```
shopper presses "Complete purchase"
        │
        ▼
  Briqpay pauses ──► POST /decision
        │
        ├─ terms unticked?   → reject, reason shown in the iframe
        ├─ totals drifted?   → reject, reason shown in the iframe
        └─ otherwise         → allow, payment proceeds
```

The module asks for that pause itself, per session, so it works without any
change to the Briqpay account. It is requested only when there is something to
check — with all three validations off, no pause is requested and the shopper
saves a round trip.

Because the checks run server-side they cannot be stepped around by editing the
page.

The module keeps the Briqpay session in step with the cart automatically, and
only sends an update when the cart data has genuinely changed.

## Order management

Capture, refund and cancel are available from the **Briqpay payment** panel on
the order page, for the full amount or a partial one. They also fire from
PrestaShop's own workflow:

| PrestaShop action | Briqpay operation | Setting |
| --- | --- | --- |
| Order → Shipped / Delivered | Capture | Capture on shipment |
| Order → Refunded | Refund (full) | Refund on refund status |
| Credit slip issued | Refund (partial) | Refund on refund status |
| Order → Cancelled | Cancel authorisation | Cancel on cancellation |

Each operation is guarded: you cannot capture more than the order total, refund
more than was captured, or cancel an order that has already been captured.

## Payment links (hosted payment pages)

For orders taken by phone or email, the module can generate a Briqpay hosted
payment page and hand the customer a link.

1. Create the order in the back office as usual.
2. On the order page, open the **Briqpay payment** panel.
3. Pick a flow and click **Create payment link**.
4. Copy the link to the customer.

| Flow | Customer type | What the page collects |
| --- | --- | --- |
| Consumer | B2C | Payment method only |
| Business - payment methods only | B2B | Payment method only |
| Business - full checkout | B2B | Company lookup, billing, shipping, payment |

The two payment-only flows pre-fill the address already on the order. The full
checkout flow deliberately does not, so the buyer enters their own details.

When the customer pays, the `order_status` webhook moves the existing order to
its paid state — no second order is created. Regenerating a link is blocked once
the order has been paid or captured, so a customer cannot be charged twice.

Enable it under **Hosted payment pages** in the module settings, where you can
also set the page title, a logo and whether the cart is shown.

## Webhooks

Briqpay notifies the shop about order, capture and refund status changes. The
endpoint is registered automatically on every session the module opens:

```
https://your-shop.example.com/module/briqpay_payment_module/webhook
```

It must be reachable from the public internet over HTTPS. If a shopper completes
payment but never returns to the shop, the `order_status` webhook creates the
order for them.

> **Note on webhook authentication.** This endpoint does not verify a request
> signature. It is written so that the request body is never trusted: only the
> session id is read from it, and the module then re-reads the authoritative
> session from Briqpay over the authenticated API before changing anything. An
> unauthenticated caller can therefore make the shop re-sync an order against
> Briqpay's own record, but cannot dictate what that record says.

## Settings

### API credentials

| Setting | Description |
| --- | --- |
| Live mode | Off routes to the playground; on moves real money. |
| Merchant ID | From **Developers** in the Briqpay dashboard. |
| Shared secret | Differs between playground and production. |

### Checkout

| Setting | Default | Description |
| --- | --- | --- |
| Payment method name | *Briqpay Payments* | Label shown to shoppers. |
| Customer type | Detect automatically | `auto` is B2B when the billing address has a company name, B2C otherwise. A VAT number alone is never enough — that field is free text and routinely holds something else. Or force either. |
| Replace the payment method list | On | Show the iframe instead of the radio list. |
| Require terms acceptance | On | Reject the purchase at the decision step if the terms box is unticked. |
| Terms URL | *(empty)* | Falls back to the shop's own conditions page. |
| Public callback URL | *(empty)* | Where Briqpay should send webhooks, when that differs from the shop's own address — behind a reverse proxy or CDN, or a tunnel in development. Only webhooks use it; shoppers still browse the normal address. |

### Order management

| Setting | Default | Description |
| --- | --- | --- |
| Capture on shipment | On | Capture when an order reaches Shipped or Delivered. |
| Refund on refund status | On | Refund on a refund state change or a credit slip. |
| Cancel on cancellation | On | Release the authorisation when an order is cancelled. |
| Advance auto-captured orders | Off | Move orders to Shipped when the method captures automatically. |

### Market: country, currency and locale

All three are read from PrestaShop — nothing to configure in the module:

| Sent to Briqpay | Taken from |
| --- | --- |
| `country` | the buyer's invoice address, falling back to the shop country |
| `currency` | the cart currency |
| `locale` | the shop language, normalised to Briqpay's form |

Locales are normalised because Briqpay wants a lowercase `language-region` pair
and PrestaShop stores BCP-47 with an uppercase region: `sv-SE` → `sv-se`,
`de_DE` → `de-de`, a bare `sv` → `sv-se`. Every English variant becomes `en-gb`,
Briqpay's English locale — PrestaShop ships English as `en-US`, which Briqpay
does not recognise.

This matters beyond presentation: **the market decides which payment methods a
shopper is offered.** A Swedish shop billing in EUR is not shown Swish; the same
shop in SEK is. If methods you expect are missing, check that the country,
currency and language agree before looking anywhere else.

> **Adding a currency after installing?** PrestaShop records which currencies a
> payment module accepts at install time and does not backfill. A currency added
> later leaves Briqpay hidden at checkout for shoppers using it, with nothing
> said. The configuration page warns when this has happened; enable it under
> **Payment → Preferences**.

### Hosted payment pages

| Setting | Default | Description |
| --- | --- | --- |
| Enable payment links | Off | Show the payment-link controls on the order page. |
| Default flow | Consumer | Pre-selected flow on the order page. |
| Page title | *(empty)* | 3-256 characters; empty uses the Briqpay default. |
| Logo URL | *(empty)* | Absolute http(s) URL ending in `.png`, `.jpg`, `.jpeg` or `.svg`. |
| Show the cart | On | Display the order lines on the payment page. |

### Validation and diagnostics

| Setting | Default | Description |
| --- | --- | --- |
| Verify totals before approving | On | Reject a purchase whose Briqpay total no longer matches the cart. |
| Verify addresses before approving | Off | Also compare billing and shipping addresses. |
| Enable logging | On | Write to **Advanced Parameters → Logs**. |
| Log level | Errors only | Debug is verbose; use it only while troubleshooting. |

Log output is redacted: secrets, tokens, emails, phone numbers and VAT numbers
are replaced with `***`.

## Extending the module

Hooks fire at each point where a merchant may need to intervene. Payload
arguments marked *by reference* can be modified in place.

| Hook | When | Modifiable |
| --- | --- | --- |
| `actionBriqpayCreateSessionBefore` | Before a session is created | `sessionData` |
| `actionBriqpayUpdateSessionBefore` | Before a session is updated | `sessionData` |
| `actionBriqpayDecisionData` | After reading the session at the decision step | `sessionData` |
| `actionBriqpayDecisionBefore` | Before the allow/reject answer is sent | `decision` |
| `actionBriqpayOrderCreated` | After a PrestaShop order is created | — |
| `actionBriqpayBeforePaymentOperation` | Before capture / refund / cancel | `payload` |
| `actionBriqpayAfterPaymentOperation` | After capture / refund / cancel | — |
| `actionBriqpayCaptureNotified` | On a capture webhook | — |
| `actionBriqpayRefundNotified` | On a refund webhook | — |
| `actionBriqpayHppSessionBefore` | Before a hosted page session is created | `sessionData` |
| `actionBriqpayHppConfigBefore` | Before the hosted page is created | `config` |
| `actionBriqpayHppCreated` | After a payment link is generated | — |

## Development

```bash
composer install

composer test        # PHPUnit
composer analyse     # PHPStan
composer lint        # php-cs-fixer, dry run
composer lint:fix    # php-cs-fixer, apply
composer check       # all three
```

The test suite runs on plain PHP with no PrestaShop install — `tests/Stub/`
provides the handful of PrestaShop classes the module touches.

A Docker-based development stack (PrestaShop 1.7.8 / 8.1 / 9.0, a Cloudflare
tunnel for webhook testing, and seeded test products) is kept in the internal
repository rather than here.

### Layout

```
briqpay_payment_module/
├── briqpay_payment_module.php   Module entry point: hooks, settings, payment option
├── src/
│   ├── Api/                     HTTP client for the Briqpay v3 API
│   ├── Builder/                 Cart, address and session payload construction
│   ├── Config/                  Typed access to every setting
│   ├── Hpp/                     Hosted payment pages (payment links)
│   ├── Install/                 Schema, hooks, order states
│   ├── Order/                   Order creation, capture/refund/cancel, status mapping
│   ├── Repository/             `briqpay_session` and `briqpay_order` tables
│   ├── Session/                 Session lifecycle
│   ├── Support/                 Logging and locking
│   ├── Validation/              Decision-step checks
│   └── Webhook/                 Notification handling
├── controllers/front/           validation, decision, updatecart, webhook
├── upgrade/                     Version-to-version upgrade scripts
└── views/                       Templates, CSS and JS
```

## Upgrading from 1.x

Install 2.0.0 over the existing module; PrestaShop runs
`upgrade/upgrade-2.0.0.php` automatically. It creates the new tables, copies
your existing payment records across, and removes the 1.x `CartController`
override that redirected the cart to a standalone Briqpay page.

Three things change that are worth knowing about:

- **Checkout moves into PrestaShop's native flow.** The standalone Briqpay page,
  its address form and its shipping selector are gone. Addresses and shipping
  are collected by PrestaShop, as with any other payment method.
- **The B2C/B2B switcher is gone.** Set *Customer type* to **Detect
  automatically** and the module reads the company field on the billing address.
- **The terms URL is now configurable.** 1.x sent a hardcoded placeholder;
  2.0 defaults to your shop's own conditions page.

If the upgrade cannot delete the override — usually a filesystem permission
problem, and the module logs it — remove it by hand:

```bash
rm override/controllers/front/CartController.php
rm override/controllers/front/OrderConfirmationController.php
rm var/cache/prod/class_index.php
```

## Releasing

Development happens on Bitbucket; releases are published from GitHub. See
[`RELEASING.md`](RELEASING.md).

```bash
./scripts/publish.sh dev     # push to Bitbucket
./scripts/publish.sh prod    # push to GitHub; tags and publishes a release
```

## Support

- Documentation: <https://docs.briqpay.com>
- Email: <hello@briqpay.com>

## License

[Academic Free License 3.0](LICENSE)
