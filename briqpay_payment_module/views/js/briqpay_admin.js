/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Back office behaviour: copy-to-clipboard on the configuration page and
 * confirmation + validation for the order panel's payment operations.
 */
(function () {
  "use strict"

  function copyToClipboard(input) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(input.value)
    }

    // execCommand is deprecated but remains the only option on the
    // non-secure-origin admin setups some merchants still run.
    input.select()
    input.setSelectionRange(0, input.value.length)
    document.execCommand("copy")

    return Promise.resolve()
  }

  function bindCopyButtons() {
    var buttons = document.querySelectorAll("[data-briqpay-copy]")

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener("click", function () {
        var input = document.getElementById(button.getAttribute("data-briqpay-copy"))
        if (!input) return

        var original = button.textContent

        copyToClipboard(input).then(function () {
          button.textContent = "Copied"
          setTimeout(function () {
            button.textContent = original
          }, 1600)
        })
      })
    })
  }

  /**
   * Every destructive button carries its own confirmation text. Bound across
   * the whole panel so it covers the payment-link form as well as the
   * capture / refund / cancel form.
   */
  function bindConfirmations() {
    var buttons = document.querySelectorAll("[data-briqpay-confirm]")

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener("click", function (event) {
        var message = button.getAttribute("data-briqpay-confirm")

        if (message && !window.confirm(message)) {
          event.preventDefault()
          event.stopImmediatePropagation()
        }
      })
    })
  }

  /**
   * Each action button writes its own operation into the shared hidden field,
   * so one form can drive capture, refund and cancel.
   */
  function bindOperationButtons() {
    var form = document.getElementById("briqpay-actions-form")
    if (!form) return

    var operationField = document.getElementById("briqpay-operation")
    var amountField = document.getElementById("briqpay-amount")
    var buttons = form.querySelectorAll("[data-briqpay-operation]")

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener("click", function (event) {
        var operation = button.getAttribute("data-briqpay-operation")
        var max = parseFloat(button.getAttribute("data-briqpay-max"))
        var amount = amountField ? parseFloat(amountField.value) : NaN

        if (!isNaN(amount) && amount > 0 && !isNaN(max) && amount > max) {
          event.preventDefault()
          event.stopImmediatePropagation()
          window.alert(
            "The amount exceeds what is still available for this operation (" +
              max.toFixed(2) +
              ")."
          )
          return
        }

        // Cancel always applies to the whole authorisation.
        if (operation === "cancel" && amountField) {
          amountField.value = ""
        }

        operationField.value = operation
      })
    })
  }

  /**
   * Show that a secret is stored.
   *
   * HelperForm emits password inputs with a hardcoded value="", and the module
   * does not own that template, so a saved secret always renders as an empty
   * box. An empty box looks exactly like a lost secret -- which is what it gets
   * reported as. Put a masked placeholder in it instead, and label the field so
   * it is clear that leaving it alone keeps the stored value.
   */
  function markStoredSecret() {
    var panel = document.getElementById("briqpay-admin")
    if (!panel) return

    var preview = panel.getAttribute("data-briqpay-secret-preview")
    if (!preview) return

    var field = document.getElementById("BRIQPAY_SECRET")
    if (!field) return

    field.setAttribute("placeholder", preview)
    field.classList.add("briqpay-secret--stored")

    // The browser will happily autofill a saved login into this field, which
    // silently replaces the merchant's secret on the next save.
    field.setAttribute("autocomplete", "new-password")
  }

  function init() {
    bindCopyButtons()
    markStoredSecret()
    // Amount validation first, so an over-limit amount is rejected before the
    // shopkeeper is asked to confirm anything.
    bindOperationButtons()
    bindConfirmations()
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init)
  } else {
    init()
  }
})()
