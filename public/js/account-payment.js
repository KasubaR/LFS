/**
 * LFS — Lusaka Fitness Squad
 * js/account-payment.js (web root)
 *
 * Wires the /account "Continue to Payment" panel to the membership
 * Lenco payment endpoints. Mirrors checkout-lenco.js's initiate/poll/verify
 * pattern, simplified for a single mobile-money payment (no cart, no steps).
 */

'use strict';

(function () {

  const POLL_INTERVAL_MS = 8_000;
  const MAX_POLLS        = 75; // ~10 minutes
  const INITIATE_URL     = '/account/payment/initiate';
  const VERIFY_URL       = '/account/payment/verify';

  let pollTimer   = null;
  let pollCount   = 0;
  let currentRef  = null;

  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('account-payment-form');
    if (!form) return;

    form.addEventListener('submit', onSubmit);

    const phoneWrap = form.querySelector('[data-phone-wrap]');
    form.querySelectorAll('input[name="provider"]').forEach((radio) => {
      radio.addEventListener('change', () => phoneWrap?.classList.add('is-visible'));
    });
  });

  async function onSubmit(event) {
    event.preventDefault();
    clearError();

    const form = event.currentTarget;
    const provider = form.querySelector('input[name="provider"]:checked')?.value ?? '';
    const phone = form.querySelector('input[name="phone"]')?.value.trim() ?? '';

    if (!provider) {
      showError('Please select MTN or Airtel Money.');
      return;
    }
    if (!phone) {
      showError('Please enter your mobile money number.');
      return;
    }

    const payBtn = form.querySelector('[data-pay-button]');
    setButtonLoading(payBtn, true, 'Initiating payment…');

    let response;
    try {
      const res = await fetch(INITIATE_URL, {
        method : 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept'      : 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(form),
        },
        body: JSON.stringify({ provider, phone }),
      });
      response = await res.json();
    } catch {
      setButtonLoading(payBtn, false);
      showError('Network error. Please check your connection and try again.');
      return;
    }

    if (!response.ok) {
      setButtonLoading(payBtn, false);
      showError(response.message || 'Could not start payment. Please try again.');
      return;
    }

    currentRef = response.reference;
    payBtn.disabled = true;
    setButtonLoading(payBtn, false);

    showResult(response.message, provider);
    startPolling(currentRef);
  }

  function getCsrfToken(form) {
    return form.querySelector('input[name="_token"]')?.value ?? '';
  }

  function showResult(message, provider) {
    const panel = document.getElementById('account-payment-result');
    const titleEl = document.getElementById('account-payment-title');
    const textEl = document.getElementById('account-payment-text');
    if (!panel) return;

    if (titleEl) {
      titleEl.textContent = provider === 'mtn' ? 'Check Your MTN Phone' : 'Check Your Airtel Phone';
    }
    if (textEl) {
      textEl.textContent = message || 'A push notification has been sent to your phone. Please approve the payment.';
    }
    panel.classList.add('is-visible');
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function startPolling(reference) {
    pollCount = 0;
    clearInterval(pollTimer);
    pollTimer = setInterval(() => checkStatus(reference), POLL_INTERVAL_MS);
  }

  async function checkStatus(reference) {
    pollCount++;
    if (pollCount > MAX_POLLS) {
      clearInterval(pollTimer);
      updateStatusMsg('Verification timed out. If payment was deducted, contact us on WhatsApp.');
      return;
    }

    try {
      const res = await fetch(`${VERIFY_URL}?reference=${encodeURIComponent(reference)}`, {
        headers: { 'Accept': 'application/json' },
      });
      const data = await res.json();
      if (!data.ok) return;

      if (data.status === 'completed') {
        clearInterval(pollTimer);
        updateStatusMsg('Payment confirmed! Reloading your account…');
        setTimeout(() => window.location.reload(), 1200);
      } else if (data.status === 'failed' || data.status === 'cancelled') {
        clearInterval(pollTimer);
        onPaymentFailed(data.status === 'cancelled' ? 'Payment was cancelled.' : 'Payment failed. Please try again.');
      } else {
        updateStatusMsg('Waiting for payment confirmation…');
      }
    } catch {
      // transient network error — keep polling silently
    }
  }

  function onPaymentFailed(message) {
    const payBtn = document.querySelector('[data-pay-button]');
    if (payBtn) payBtn.disabled = false;

    showError(message);
    document.getElementById('account-payment-result')?.classList.remove('is-visible');
  }

  function updateStatusMsg(msg) {
    const el = document.getElementById('account-payment-status');
    if (el) el.textContent = msg;
  }

  function showError(msg) {
    const errEl = document.getElementById('account-payment-error');
    const textEl = document.getElementById('account-payment-error-text');
    if (!errEl) return;
    if (textEl) textEl.textContent = msg;
    errEl.classList.add('is-visible');
    errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearError() {
    document.getElementById('account-payment-error')?.classList.remove('is-visible');
  }

  function setButtonLoading(btn, loading, label = '') {
    if (!btn) return;
    btn.disabled = loading;
    btn.innerHTML = loading
      ? `<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> ${label}`
      : btn.innerHTML;
  }

})();
