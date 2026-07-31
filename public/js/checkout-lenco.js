/**
 * LFS — Lusaka Fitness Squad
 * js/checkout-lenco.js (web root)
 *
 * Wires the checkout Step 3 to the Lenco payment API.
 *
 * HOW IT WORKS
 * ─────────────
 * Step 3 "Place Order" click:
 *   1. Collects customer info (from Step 2 fields) + payment method + phone
 *   2. POSTs to /shop/checkout/place-order  (OrderController::placeOrder)
 *   3. On success → shows Lenco instructions + starts status polling
 *   4. Polling GETs /shop/checkout/verify?txId=xxx every 8 seconds
 *   5. On 'completed' → advances checkout to Step 3 (confirmation)
 *   6. On 'failed'    → shows error, re-enables button
 *
 * LOADING
 * ───────
 * Add to checkout.php $scripts:
 *   $scripts = '<script src="/js/checkout.js"></script>'
 *            . '<script src="/js/checkout-lenco.js"></script>';
 *
 * This file attaches after checkout.js runs, overriding only the
 * Place Order button handler.
 */

'use strict';

(function () {

  /* ── Config ── */
  const POLL_INTERVAL_MS = 8_000;  // 8 s between status checks
  const MAX_POLLS        = 75;     // ~10 minutes total
  const PLACE_ORDER_URL  = '/shop/checkout/place-order';
  const VERIFY_URL       = '/shop/checkout/verify';

  /* ── State ── */
  let pollTimer    = null;
  let pollCount    = 0;
  let currentTxId  = null;
  let currentOrder = null;

  /* ─────────────────────────────────────────────────────────────
     INIT — runs after DOM is ready
  ───────────────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    initPaymentMethodToggle();
    overridePlaceOrderButton();
  });

  /* ─────────────────────────────────────────────────────────────
     Payment method radio → show/hide phone input
  ───────────────────────────────────────────────────────────── */
  function initPaymentMethodToggle() {
    const radios = document.querySelectorAll('input[name="paymentMethod"]');
    radios.forEach(radio => {
      radio.addEventListener('change', () => showPhoneFieldFor(radio.value));
    });
    // Show for any pre-checked value
    const checked = document.querySelector('input[name="paymentMethod"]:checked');
    if (checked) showPhoneFieldFor(checked.value);
  }

  function showPhoneFieldFor(provider) {
    document.querySelectorAll('.payment-details--phone').forEach(el => {
      el.style.display = 'none';
    });
    const target = document.querySelector(`[data-payment-details="${provider}"]`);
    if (target) target.style.display = '';
  }

  /* ─────────────────────────────────────────────────────────────
     Override the Place Order button
  ───────────────────────────────────────────────────────────── */
  function overridePlaceOrderButton() {
    const btn = document.querySelector('[data-place-order]');
    if (!btn) return;

    // Remove any listener attached by checkout.js
    const freshBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(freshBtn, btn);

    freshBtn.addEventListener('click', onPlaceOrder);
  }

  /* ─────────────────────────────────────────────────────────────
     Place Order — main handler
  ───────────────────────────────────────────────────────────── */
  async function onPlaceOrder() {
    clearError();

    // ── Collect customer info from Step 2 fields ──
    const customerInfo = collectCustomerInfo();
    if (!customerInfo) return; // validation failed — error already shown

    // ── Collect payment method + phone ──
    const provider = document.querySelector('input[name="paymentMethod"]:checked')?.value ?? '';
    if (!provider || !['mtn', 'airtel'].includes(provider)) {
      showError('Please select a payment method (MTN or Airtel Money).');
      return;
    }

    const phoneInput = document.querySelector(`input.payment-phone-input[data-provider="${provider}"]`);
    const phone      = phoneInput?.value.trim() ?? '';
    if (!phone) {
      showError(`Please enter your ${provider.toUpperCase()} Mobile Money number.`);
      phoneInput?.focus();
      return;
    }

    // ── UI feedback ──
    const placeBtn = document.querySelector('[data-place-order]');
    setButtonLoading(placeBtn, true, 'Initiating payment…');
    showOverlay('Sending payment request…');

    // ── POST to server ──
    const csrfToken = getCsrfToken();
    let response;
    try {
      const res = await fetch(PLACE_ORDER_URL, {
        method : 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept'       : 'application/json',
          'X-CSRF-Token' : csrfToken,
        },
        body: JSON.stringify({
          customerInfo,
          paymentMethod : 'mobile_money',
          provider,
          customerPhone : phone,
        }),
      });
      response = await res.json();
    } catch {
      hideOverlay();
      setButtonLoading(placeBtn, false);
      showError('Network error. Please check your connection and try again.');
      return;
    }

    hideOverlay();

    if (!response.ok) {
      setButtonLoading(placeBtn, false);
      showError(response.message || 'Could not place your order. Please try again.');
      return;
    }

    // ── Success: store state, show instructions, start polling ──
    currentTxId  = response.transactionId || response.reference;
    currentOrder = {
      orderNumber  : response.orderNumber,
      instructions : response.paymentInstructions || response.message,
      expiresAt    : response.expiresAt,
    };

    placeBtn.disabled = true; // prevent double-submit during transition
    setButtonLoading(placeBtn, false);

    showPaymentInstructions(currentOrder.instructions, provider);
    startPolling(currentTxId);
  }

  /* ─────────────────────────────────────────────────────────────
     Collect customer info from Step 2 form fields
     Returns null if validation fails.
  ───────────────────────────────────────────────────────────── */
  function collectCustomerInfo() {
    const name  = document.querySelector('#checkout-name,  [name="fullName"],  [name="name"]')  ?.value.trim() ?? '';
    const email = document.querySelector('#checkout-email, [name="email"]')                      ?.value.trim() ?? '';
    const phone = document.querySelector('#checkout-phone, [name="phone"]')                      ?.value.trim() ?? '';
    const notes = document.querySelector('#checkout-notes, [name="notes"]')                      ?.value.trim() ?? '';

    if (!name) {
      showError('Please enter your full name in Step 2 before placing your order.');
      return null;
    }
    if (!email || !email.includes('@')) {
      showError('Please enter a valid email address in Step 2 before placing your order.');
      return null;
    }
    return { name, email, phone, notes };
  }

  /* ─────────────────────────────────────────────────────────────
     Show Lenco payment instructions panel
  ───────────────────────────────────────────────────────────── */
  function showPaymentInstructions(instructions, provider) {
    const panel    = document.getElementById('lenco-payment-result');
    const titleEl  = document.getElementById('lenco-instructions-title');
    const textEl   = document.getElementById('lenco-instructions-text');

    if (!panel) return;

    if (titleEl) {
      titleEl.textContent = provider === 'mtn'
        ? 'Check Your MTN Phone'
        : 'Check Your Airtel Phone';
    }

    if (textEl) {
      textEl.textContent = instructions || 'A push notification has been sent to your phone. Please approve the payment.';
    }

    panel.style.display = '';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /* ─────────────────────────────────────────────────────────────
     Status polling
  ───────────────────────────────────────────────────────────── */
  function startPolling(txId) {
    pollCount = 0;
    clearInterval(pollTimer);
    pollTimer = setInterval(() => checkStatus(txId), POLL_INTERVAL_MS);

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        clearInterval(pollTimer);
      } else if (currentTxId) {
        // Resume polling immediately when tab becomes visible again
        checkStatus(currentTxId);
        pollTimer = setInterval(() => checkStatus(currentTxId), POLL_INTERVAL_MS);
      }
    });
  }

  async function checkStatus(txId) {
    pollCount++;

    if (pollCount > MAX_POLLS) {
      clearInterval(pollTimer);
      updatePollingMsg('⏰ Verification timed out. If payment was deducted, contact us on WhatsApp.');
      return;
    }

    try {
      const res  = await fetch(`${VERIFY_URL}?txId=${encodeURIComponent(txId)}`, {
        headers: { 'Accept': 'application/json' }
      });
      const data = await res.json();

      if (!data.ok) return; // transient error — keep polling

      updatePollingMsg(statusLabel(data.status, data.lencoStatus));

      if (data.status === 'completed') {
        clearInterval(pollTimer);
        onPaymentConfirmed(data);
      } else if (data.status === 'failed') {
        clearInterval(pollTimer);
        onPaymentFailed(data);
      } else if (data.status === 'cancelled') {
        clearInterval(pollTimer);
        onPaymentFailed({ ...data, message: 'Payment was cancelled.' });
      }
    } catch {
      // Network hiccup — keep polling silently
    }
  }

  function statusLabel(status, lencoStatus) {
    if (status === 'completed') return '✅ Payment confirmed!';
    if (status === 'failed')    return '❌ Payment failed.';
    if (status === 'cancelled') return '🚫 Payment cancelled.';
    if (lencoStatus === 'pay-offline') {
      return '📱 Waiting for you to approve on your phone…';
    }
    if (status === 'processing') return '⏳ Processing your payment…';
    return '⏳ Waiting for payment confirmation…';
  }

  function updatePollingMsg(msg) {
    const el = document.getElementById('lenco-polling-msg');
    if (el) el.textContent = msg;
  }

  /* ─────────────────────────────────────────────────────────────
     Terminal outcomes
  ───────────────────────────────────────────────────────────── */
  function onPaymentConfirmed(data) {
    // Populate Step 3 confirmation panel
    const orderValEl = document.querySelector('.checkout-confirmation__order-val');
    if (orderValEl && currentOrder?.orderNumber) {
      orderValEl.textContent = currentOrder.orderNumber;
    }

    // Advance to Step 3 (confirmation) via the existing checkout.js goToStep helper if available
    if (typeof window.goToStep === 'function') {
      window.goToStep(3);
    } else {
      // Fallback: direct DOM manipulation
      document.querySelectorAll('.checkout-panel').forEach(p => p.classList.remove('checkout-panel--active'));
      document.querySelector('[data-step-panel="3"]')?.classList.add('checkout-panel--active');
      document.querySelectorAll('.checkout-step').forEach(s => s.classList.remove('checkout-step--active', 'checkout-step--done'));
      for (let i = 1; i < 3; i++) {
        document.querySelector(`[data-step="${i}"]`)?.classList.add('checkout-step--done');
      }
      document.querySelector('[data-step="3"]')?.classList.add('checkout-step--active');
    }
  }

  function onPaymentFailed(data) {
    const placeBtn = document.querySelector('[data-place-order]');
    if (placeBtn) placeBtn.disabled = false;

    const msg = data.failureReason || data.message || 'Payment was not successful. Please try again.';
    showError(msg);

    updatePollingMsg('');
    const panel = document.getElementById('lenco-payment-result');
    if (panel) panel.style.display = 'none';
  }

  /* ─────────────────────────────────────────────────────────────
     UI helpers
  ───────────────────────────────────────────────────────────── */
  function showError(msg) {
    const errEl  = document.getElementById('lenco-error');
    const textEl = document.getElementById('lenco-error-text');
    if (!errEl) return;
    if (textEl) textEl.textContent = msg;
    errEl.style.display = '';
    errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearError() {
    const errEl = document.getElementById('lenco-error');
    if (errEl) errEl.style.display = 'none';
  }

  function setButtonLoading(btn, loading, label = '') {
    if (!btn) return;
    btn.disabled = loading;
    btn.innerHTML = loading
      ? `<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> ${label}`
      : '<i class="fas fa-lock" aria-hidden="true"></i> Place Order';
  }

  function showOverlay(msg = 'Processing…') {
    const overlay = document.querySelector('.checkout-loading');
    const msgEl   = document.querySelector('.checkout-loading__msg');
    if (overlay) overlay.classList.add('is-active');
    if (msgEl)   msgEl.textContent = msg;
  }

  function hideOverlay() {
    document.querySelector('.checkout-loading')?.classList.remove('is-active');
  }

})();
