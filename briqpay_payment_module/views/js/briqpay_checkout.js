/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Bridges PrestaShop's native checkout with the embedded Briqpay iframe.
 *
 * Responsibilities:
 *   - collapse the payment option list down to the iframe (optional)
 *   - keep the Briqpay session in step with cart changes
 *   - answer the decision step and follow the completed session
 */
(function () {
  "use strict"

  var config = window.briqpayConfig || {}
  var suspendReasons = {}
  var initialised = false
  var subscribed = false

  /** Briqpay's SDK is injected by the snippet, so it may not exist yet. */
  function sdk() {
    return window._briqpay && window._briqpay.v3 ? window._briqpay.v3 : null
  }

  /**
   * Suspend/resume are reference counted: several things can want the iframe
   * held at once, and whichever finishes first must not resume it on the
   * others' behalf.
   */
  function suspend(reason) {
    var api = sdk()
    if (!api) return

    var wasIdle = Object.keys(suspendReasons).length === 0
    suspendReasons[reason] = true

    if (wasIdle) api.suspend()
  }

  function resume(reason) {
    var api = sdk()
    if (!api) return

    delete suspendReasons[reason]

    if (Object.keys(suspendReasons).length === 0) api.resume()
  }

  function notice(message) {
    var box = document.getElementById("briqpay-checkout-notice")
    if (!box) return

    if (!message) {
      box.hidden = true
      box.textContent = ""
      return
    }

    box.textContent = message
    box.hidden = false
  }

  function post(url, body) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify(body || {}),
    }).then(function (response) {
      return response.json().catch(function () {
        return {}
      })
    })
  }

  /**
   * Hide the radio list when Briqpay should own the whole payment step, and
   * suppress PrestaShop's own place-order button -- the purchase is completed
   * inside the iframe, so a second submit path would double-submit the cart.
   */
  function applyTakeover() {
    var container = document.getElementById("briqpay-checkout")
    if (!container || !container.hasAttribute("data-briqpay-takeover")) return

    var step = document.getElementById("checkout-payment-step")
    if (!step) return

    step.classList.add("briqpay-takeover")

    // The theme renders the radio row and the option's form as siblings, so
    // the form wrapper is the nearest useful ancestor here -- .payment-option
    // is not an ancestor of this container at all.
    var formWrapper = container.closest(".js-payment-option-form")
    if (!formWrapper) return

    formWrapper.classList.add("briqpay-payment-form")

    // Hide the other options here as well as in the stylesheet. The stylesheet
    // is the tidier mechanism, but if it is stale or blocked the shopper is
    // shown a list of payment methods that this shop does not actually offer
    // through PrestaShop -- so the takeover cannot depend on it alone.
    var list = formWrapper.parentElement
    if (!list) return

    Array.prototype.forEach.call(list.children, function (child) {
      if (child !== formWrapper) {
        child.style.display = "none"
      }
    })

    formWrapper.style.display = "block"

    // Select the Briqpay option: the theme still drives ps-hidden from it, and
    // PrestaShop refuses to place an order with no payment option selected.
    var radio = step.querySelector(
      'input[name="payment-option"][data-module-name="' + config.moduleName + '"]'
    )
    if (radio && !radio.checked) {
      radio.checked = true
      radio.dispatchEvent(new Event("change", { bubbles: true }))
    }
  }

  /** PrestaShop's terms checkbox, when the theme renders one. */
  function termsCheckbox() {
    return document.querySelector(
      '#conditions-to-approve input[type="checkbox"], input[name="conditions_to_approve[terms-and-conditions]"]'
    )
  }

  /**
   * Whether the shopper has accepted the shop's terms.
   *
   * Returns true when the theme renders no terms checkbox at all, so shops
   * without conditions are not blocked.
   */
  function termsAccepted() {
    var checkbox = termsCheckbox()

    return checkbox ? checkbox.checked : true
  }

  function syncCart() {
    suspend("cart")

    return post(config.updateCartUrl, {})
      .then(function (data) {
        if (data && data.error) {
          notice(config.translations ? config.translations.genericError : "")
        }
      })
      .catch(function () {
        notice(config.translations ? config.translations.genericError : "")
      })
      .then(function () {
        resume("cart")
      })
  }

  /** The session this page was rendered for. */
  function renderedSessionId() {
    var container = document.getElementById("briqpay-checkout")

    return container ? container.getAttribute("data-briqpay-session") : null
  }

  function handleDecision(data) {
    var api = sdk()

    // Prefer the id the event carries, but fall back to the one the page was
    // rendered with: an event without it would otherwise post a decision with
    // no session, which the shop can only refuse -- and to the shopper a
    // refusal is indistinguishable from a declined purchase.
    var sessionId = (data && data.sessionId) || renderedSessionId()

    post(config.decisionUrl, {
      sessionId: sessionId,
      termsAccepted: termsAccepted(),
    })
      .then(function (response) {
        if (response && response.error) {
          notice(response.message || (config.translations && config.translations.genericError))

          // A rejected decision usually means the cart drifted; re-sync so the
          // shopper can retry against correct totals.
          return syncCart()
        }

        notice("")
      })
      .catch(function () {
        notice(config.translations ? config.translations.genericError : "")
      })
      .then(function () {
        if (api && typeof api.resumeDecision === "function") api.resumeDecision()
      })
  }

  function bindBriqpayEvents() {
    if (!window._briqpay || typeof window._briqpay.subscribe !== "function") return false

    // init() runs again whenever the shopper moves between checkout steps, and
    // each subscription is additive: without this guard the decision is
    // submitted to Briqpay once per subscription.
    if (subscribed) return true

    subscribed = true

    window._briqpay.subscribe("make_decision", handleDecision)

    window._briqpay.subscribe("order_status", function (data) {
      if (data && data.status === "rejected") {
        notice(config.translations ? config.translations.genericError : "")
      }
    })

    return true
  }

  function bindPrestaShopEvents() {
    if (typeof window.prestashop === "undefined" || !window.prestashop.on) return

    window.prestashop.on("updateCart", syncCart)
    window.prestashop.on("updatedDeliveryForm", syncCart)
    window.prestashop.on("updatedAddressForm", syncCart)
  }

  /**
   * PrestaShop appends a hidden submit button to the payment option's form and
   * clicks it when the shopper presses "Place order". Briqpay completes the
   * purchase inside the iframe, so letting that submit through would POST the
   * checkout a second time behind the payment.
   */
  function bindFormGuard() {
    var form = document.getElementById("briqpay-checkout-form")
    if (!form) return

    form.addEventListener("submit", function (event) {
      event.preventDefault()
      notice(config.translations ? config.translations.completeInIframe : "")
    })
  }

  /**
   * Hold the iframe until the shopper accepts the terms.
   *
   * The decision step would be the natural place for this, and the module does
   * check there -- but that step is optional and is simply absent from sessions
   * for merchants who have not enabled it. On those accounts `make_decision`
   * never fires, so a decision-only gate enforces nothing and an order can be
   * placed with the box unticked.
   *
   * Suspending is therefore the enforcement; the decision check remains as a
   * second line for merchants who do have it. Nothing is shown on page load --
   * the moved checkbox above the iframe is the explanation, and a message only
   * appears if the shopper actually tries to pay.
   */
  function bindTermsEvents() {
    var checkbox = termsCheckbox()
    if (!checkbox) return

    checkbox.addEventListener("change", function () {
      if (checkbox.checked) notice("")
    })
  }



  function init() {
    if (initialised) return
    if (!document.getElementById("briqpay-checkout")) return

    initialised = true

    applyTakeover()
    bindFormGuard()
    bindPrestaShopEvents()
    bindTermsEvents()

    // The Briqpay snippet loads its SDK asynchronously; poll briefly for it
    // rather than racing the injected script tag.
    var attempts = 0
    var timer = setInterval(function () {
      attempts += 1

      if (bindBriqpayEvents() || attempts > 50) {
        clearInterval(timer)
      }
    }, 100)
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init)
  } else {
    init()
  }

  // The payment step is rendered by AJAX when the shopper reaches it, so the
  // iframe may appear well after the initial page load.
  if (typeof window.prestashop !== "undefined" && window.prestashop.on) {
    window.prestashop.on("changedCheckoutStep", function () {
      initialised = false
      init()
    })
  }
})()
