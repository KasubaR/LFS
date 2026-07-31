/* ============================================================
   LFS — Lusaka Fitness Squad | checkout.js
   Multi-step checkout flow.
   Depends on: cart.js (cartFetch, updateCartBadge, getCsrfToken)
   Loaded on /shop/checkout only.
   ============================================================ */

'use strict';

/* ─────────────────────────────────────────────────────────────
   STATE
───────────────────────────────────────────────────────────── */

const CheckoutState = {
  currentStep: 1,
  totalSteps: 3,      // 1 Your Info | 2 Payment | 3 Confirmed
  customer: {},
  paymentMethod: null,
  paymentReference: '',
  orderId: null,
};

/* ─────────────────────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────────────────────── */

/** Format Kwacha amounts. */
function formatKwacha(amount) {
  return 'K\u00a0' + Math.round(amount).toLocaleString();
}

/** Show/hide the full-page loading overlay. */
function setLoading(visible, msg = 'Processing…') {
  const overlay = document.querySelector('.checkout-loading');
  if (!overlay) return;
  overlay.querySelector('.checkout-loading__msg').textContent = msg;
  overlay.classList.toggle('checkout-loading--visible', visible);
}

/** Copy text to clipboard, show brief feedback on the triggering button. */
function copyToClipboard(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
    setTimeout(() => { btn.innerHTML = original; }, 1800);
  });
}

/** Generate a random order reference (used client-side for display
    until the server confirms; the server should override this). */
function generateOrderRef() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
  let ref = 'LFS-';
  for (let i = 0; i < 8; i++) ref += chars[Math.floor(Math.random() * chars.length)];
  return ref;
}

/* ─────────────────────────────────────────────────────────────
   STEP NAVIGATION
───────────────────────────────────────────────────────────── */

/**
 * Navigate to a specific step number.
 * Performs validation before advancing forward.
 */
function goToStep(step) {
  const total = CheckoutState.totalSteps;
  if (step < 1 || step > total) return;

  // Validate current step before advancing
  if (step > CheckoutState.currentStep) {
    if (!validateStep(CheckoutState.currentStep)) return;
  }

  // Hide all panels
  document.querySelectorAll('.checkout-panel').forEach(p => {
    p.classList.remove('checkout-panel--active');
  });

  // Activate target panel
  const target = document.querySelector(`[data-step-panel="${step}"]`);
  if (target) target.classList.add('checkout-panel--active');

  // Update progress indicator
  document.querySelectorAll('.checkout-step').forEach(el => {
    const n = parseInt(el.dataset.step, 10);
    el.classList.remove('checkout-step--active', 'checkout-step--done');
    if (n === step)    el.classList.add('checkout-step--active');
    if (n < step)     el.classList.add('checkout-step--done');
  });

  CheckoutState.currentStep = step;

  // Scroll to top of checkout section
  const section = document.querySelector('.checkout-section');
  if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });

  // Sync sidebar on every step
  syncSidebarSummary();
}

// Expose for checkout-lenco.js
window.goToStep = goToStep;

/* ─────────────────────────────────────────────────────────────
   VALIDATION
───────────────────────────────────────────────────────────── */

/**
 * Validate fields for the given step.
 * Returns true if valid, false + marks errors if not.
 */
function validateStep(step) {
  clearErrors();

  if (step === 1) {
    return validateCustomerForm();
  }

  if (step === 2) {
    return validatePayment();
  }

  return true;
}

function clearErrors() {
  document.querySelectorAll('.checkout-form__group.has-error').forEach(g => {
    g.classList.remove('has-error');
  });
  document.querySelectorAll('.checkout-form__input.is-error, .checkout-form__textarea.is-error').forEach(el => {
    el.classList.remove('is-error');
  });
}

function markError(input, msg) {
  const group = input.closest('.checkout-form__group');
  if (group) {
    group.classList.add('has-error');
    const errEl = group.querySelector('.checkout-form__error-msg');
    if (errEl && msg) errEl.textContent = msg;
  }
  input.classList.add('is-error');
  input.focus();
}

function validateCustomerForm() {
  const form = document.querySelector('[data-checkout-form="customer"]');
  if (!form) return true;

  let valid = true;
  let firstError = null;

  // Full name
  const nameInput = form.querySelector('[name="fullName"]');
  if (nameInput && nameInput.value.trim().length < 3) {
    markError(nameInput, 'Please enter your full name.');
    if (!firstError) firstError = nameInput;
    valid = false;
  }

  // Phone
  const phoneInput = form.querySelector('[name="phone"]');
  if (phoneInput) {
    const phone = phoneInput.value.replace(/\D/g, '');
    if (phone.length < 9) {
      markError(phoneInput, 'Please enter a valid phone number.');
      if (!firstError) firstError = phoneInput;
      valid = false;
    }
  }

  // Email (optional but validate if filled)
  const emailInput = form.querySelector('[name="email"]');
  if (emailInput && emailInput.value.trim()) {
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim());
    if (!emailOk) {
      markError(emailInput, 'Please enter a valid email address.');
      if (!firstError) firstError = emailInput;
      valid = false;
    }
  }

  if (!valid && firstError) firstError.focus();
  return valid;
}

function validatePayment() {
  const selected = document.querySelector('input[name="paymentMethod"]:checked');
  if (!selected) {
    if (window.showToast) window.showToast('Please select a payment method.', 'orange', 3000);
    return false;
  }

  CheckoutState.paymentMethod = selected.value;

  // If bank transfer or mobile money — reference is required
  const requiresRef = ['bank', 'mtn', 'airtel'];
  if (requiresRef.includes(selected.value)) {
    const refInput = document.querySelector(`[data-payment-details="${selected.value}"] .payment-ref-input`);
    if (refInput && !refInput.value.trim()) {
      refInput.classList.add('is-error');
      refInput.focus();
      if (window.showToast) window.showToast('Please enter your payment reference.', 'orange', 3000);
      return false;
    }
    if (refInput) CheckoutState.paymentReference = refInput.value.trim();
  }

  return true;
}

/* ─────────────────────────────────────────────────────────────
   SIDEBAR SYNC
───────────────────────────────────────────────────────────── */

/**
 * Rebuild the sidebar summary from cart items currently on the page.
 * Falls back to server-rendered values if DOM items are unavailable.
 */
function syncSidebarSummary() {
  const sidebarItems = document.querySelector('.checkout-summary__items');
  if (!sidebarItems) return;

  const cartItems = document.querySelectorAll('.checkout-cart-item');
  if (cartItems.length === 0) return;

  sidebarItems.innerHTML = '';

  let subtotal = 0;

  cartItems.forEach(item => {
    const price = parseFloat(item.dataset.price || '0');
    const qty   = parseInt(item.querySelector('.checkout-qty-input')?.value || '1', 10);
    const name  = item.querySelector('.checkout-cart-item__name')?.textContent || '';
    const meta  = item.querySelector('.checkout-cart-item__meta')?.textContent || '';
    const imgSrc = item.querySelector('.checkout-cart-item__img')?.src || '';

    subtotal += price * qty;

    const el = document.createElement('div');
    el.className = 'checkout-summary__item';
    el.innerHTML = `
      <img class="checkout-summary__item-img" src="${imgSrc}" alt="${name}" loading="lazy">
      <div class="checkout-summary__item-info">
        <div class="checkout-summary__item-name">${name}</div>
        <div class="checkout-summary__item-meta">${meta} × ${qty}</div>
      </div>
      <div class="checkout-summary__item-price">${formatKwacha(price * qty)}</div>
    `;
    sidebarItems.appendChild(el);
  });

  // Update totals
  document.querySelectorAll('.checkout-summary__subtotal-val').forEach(el => {
    el.textContent = formatKwacha(subtotal);
  });
  document.querySelectorAll('.checkout-summary__total-val').forEach(el => {
    el.textContent = formatKwacha(subtotal);
  });
}

/* ─────────────────────────────────────────────────────────────
   CART (optional — no longer a step; kept for any future use)
───────────────────────────────────────────────────────────── */

function initCheckoutCart() {
  const itemsEl = document.querySelector('.checkout-cart-items');
  if (!itemsEl) return;

  /* Qty stepper buttons */
  itemsEl.addEventListener('click', (e) => {
    const stepBtn = e.target.closest('[data-step]');
    if (!stepBtn) return;
    const input = stepBtn.closest('.checkout-cart-item__qty')?.querySelector('.checkout-qty-input');
    if (!input) return;
    const newQty = Math.max(1, parseInt(input.value, 10) + parseInt(stepBtn.dataset.step, 10));
    input.value = newQty;
    input.dispatchEvent(new Event('change'));
  });

  /* Qty input change */
  itemsEl.addEventListener('change', async (e) => {
    const input = e.target.closest('.checkout-qty-input');
    if (!input) return;

    const key  = input.dataset.key;
    const qty  = Math.max(1, parseInt(input.value, 10) || 1);
    const row  = input.closest('.checkout-cart-item');

    input.value = qty; // enforce minimum 1 in checkout (remove via ✕ button)

    const data = await window.cartFetch?.('/shop/cart/update', { key, qty });
    if (!data) return;

    if (window.updateCartBadge) window.updateCartBadge(data.itemCount);

    const unitPrice   = parseFloat(row?.dataset?.price || '0');
    const lineTotalEl = row?.querySelector('.checkout-cart-item__line-total');
    if (lineTotalEl) lineTotalEl.textContent = formatKwacha(unitPrice * qty);

    syncSidebarSummary();
  });

  /* Remove button */
  itemsEl.addEventListener('click', async (e) => {
    const removeBtn = e.target.closest('[data-remove-key]');
    if (!removeBtn) return;

    const key = removeBtn.dataset.removeKey;
    const row = removeBtn.closest('.checkout-cart-item');

    removeBtn.disabled = true;
    removeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const data = await window.cartFetch?.('/shop/cart/remove', { key });
    if (data) {
      row?.remove();
      if (window.updateCartBadge) window.updateCartBadge(data.itemCount);
      syncSidebarSummary();

      // If cart is now empty, redirect to cart page
      if (data.itemCount === 0) {
        window.location.href = '/shop/cart';
      }
    } else {
      removeBtn.disabled = false;
      removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    }
  });
}

/* ─────────────────────────────────────────────────────────────
   STEP 1 — CUSTOMER INFORMATION
───────────────────────────────────────────────────────────── */

function initCustomerForm() {
  const form = document.querySelector('[data-checkout-form="customer"]');
  if (!form) return;

  // Persist values to state on blur for resilience
  form.querySelectorAll('input, textarea').forEach(field => {
    field.addEventListener('blur', () => {
      CheckoutState.customer[field.name] = field.value.trim();
    });

    // Clear error state on input
    field.addEventListener('input', () => {
      field.classList.remove('is-error');
      const group = field.closest('.checkout-form__group');
      if (group) group.classList.remove('has-error');
    });
  });
}

/* ─────────────────────────────────────────────────────────────
   STEP 2 — PAYMENT METHOD
───────────────────────────────────────────────────────────── */

function initPaymentMethods() {
  const paymentInputs = document.querySelectorAll('input[name="paymentMethod"]');
  if (!paymentInputs.length) return;

  paymentInputs.forEach(input => {
    input.addEventListener('change', () => {
      // Hide all detail panels
      document.querySelectorAll('.payment-details').forEach(panel => {
        panel.classList.remove('payment-details--active');
      });

      // Show matching detail panel
      const detailPanel = document.querySelector(`[data-payment-details="${input.value}"]`);
      if (detailPanel) detailPanel.classList.add('payment-details--active');

      CheckoutState.paymentMethod = input.value;
    });
  });

  // Copy-to-clipboard buttons
  document.querySelectorAll('.payment-copy-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const text = btn.dataset.copy;
      if (text) copyToClipboard(text, btn);
    });
  });

  // Clear ref input error on type
  document.querySelectorAll('.payment-ref-input').forEach(input => {
    input.addEventListener('input', () => input.classList.remove('is-error'));
  });
}

/* ─────────────────────────────────────────────────────────────
   PLACE ORDER — final submission
───────────────────────────────────────────────────────────── */

async function placeOrder() {
  if (!validateStep(2)) return;

  // Collect customer info from form
  const form = document.querySelector('[data-checkout-form="customer"]');
  if (form) {
    form.querySelectorAll('input, textarea').forEach(field => {
      if (field.name) CheckoutState.customer[field.name] = field.value.trim();
    });
  }

  setLoading(true, 'Placing your order…');

  const payload = {
    customer:         CheckoutState.customer,
    paymentMethod:    CheckoutState.paymentMethod,
    paymentReference: CheckoutState.paymentReference,
  };

  let data = null;
  try {
    const res = await fetch('/shop/checkout/place', {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept':        'application/json',
        'X-CSRF-Token':  window.getCsrfToken ? window.getCsrfToken() : '',
      },
      body: JSON.stringify(payload),
    });
    data = await res.json();
  } catch {
    data = null;
  }

  setLoading(false);

  if (data && data.ok) {
    CheckoutState.orderId = data.orderId || generateOrderRef();
    renderConfirmation(data);
    goToStep(3);
    if (window.updateCartBadge) window.updateCartBadge(0);
  } else {
    const msg = data?.message || 'Could not place your order. Please try again.';
    if (window.showToast) window.showToast(msg, 'red', 4000);
  }
}

/* ─────────────────────────────────────────────────────────────
   CONFIRMATION — populate the success panel
───────────────────────────────────────────────────────────── */

function renderConfirmation(data) {
  // Order number
  const orderNumEl = document.querySelector('.checkout-confirmation__order-val');
  if (orderNumEl) orderNumEl.textContent = data.orderId || CheckoutState.orderId;

  // Summary rows (uses sidebar data or server response)
  const summaryEl = document.querySelector('.checkout-confirmation__summary');
  if (!summaryEl) return;

  const subtotalEl = document.querySelector('.checkout-summary__total-val');
  const subtotal   = subtotalEl?.textContent || '—';

  const customer = CheckoutState.customer;
  summaryEl.innerHTML = `
    <div class="checkout-confirmation__summary-title">Order Details</div>
    <div class="checkout-confirmation__summary-row">
      <span>Name</span><span>${customer.fullName || '—'}</span>
    </div>
    <div class="checkout-confirmation__summary-row">
      <span>Phone</span><span>${customer.phone || '—'}</span>
    </div>
    <div class="checkout-confirmation__summary-row">
      <span>Payment</span><span>${formatPaymentLabel(CheckoutState.paymentMethod)}</span>
    </div>
    <div class="checkout-confirmation__summary-row">
      <span>Pickup</span><span>LFS Pickup Point, Lusaka</span>
    </div>
    <div class="checkout-confirmation__summary-row">
      <span>Order Total</span><span>${data.total || subtotal}</span>
    </div>
  `;
}

function formatPaymentLabel(method) {
  const labels = {
    mtn:    'MTN Mobile Money',
    airtel: 'Airtel Money',
    bank:   'Bank Transfer',
    card:   'Visa / Mastercard',
    visa:   'Visa Card',
    mc:     'Mastercard',
  };
  return labels[method] || method || '—';
}

/* ─────────────────────────────────────────────────────────────
   NAVIGATION BUTTON WIRING
───────────────────────────────────────────────────────────── */

function initNavButtons() {
  document.addEventListener('click', (e) => {
    // Next / advance
    const nextBtn = e.target.closest('[data-checkout-next]');
    if (nextBtn) {
      goToStep(CheckoutState.currentStep + 1);
      return;
    }

    // Back
    const backBtn = e.target.closest('[data-checkout-back]');
    if (backBtn) {
      goToStep(CheckoutState.currentStep - 1);
      return;
    }

    // Jump to specific step (progress indicator)
    const stepBtn = e.target.closest('[data-goto-step]');
    if (stepBtn) {
      const target = parseInt(stepBtn.dataset.gotoStep, 10);
      if (target < CheckoutState.currentStep) goToStep(target); // can go back freely
      return;
    }

    // Place order
    const placeBtn = e.target.closest('[data-place-order]');
    if (placeBtn) {
      placeOrder();
      return;
    }
  });
}

/* ─────────────────────────────────────────────────────────────
   BOOT
───────────────────────────────────────────────────────────── */

/**
 * Reset UI to step 1 on page load/refresh (no validation, no scroll).
 * Ensures a refresh always shows Your Info, not a later step.
 */
function resetToStep1() {
  CheckoutState.currentStep = 1;
  document.querySelectorAll('.checkout-panel').forEach(p => {
    p.classList.remove('checkout-panel--active');
  });
  const panel1 = document.querySelector('[data-step-panel="1"]');
  if (panel1) panel1.classList.add('checkout-panel--active');
  document.querySelectorAll('.checkout-step').forEach(el => {
    const n = parseInt(el.dataset.step, 10);
    el.classList.remove('checkout-step--active', 'checkout-step--done');
    if (n === 1) el.classList.add('checkout-step--active');
  });
}

document.addEventListener('DOMContentLoaded', () => {
  // Always start on step 1 on load/refresh (before any other init)
  resetToStep1();
  initCheckoutCart();
  initCustomerForm();
  initPaymentMethods();
  initNavButtons();
  syncSidebarSummary();
});
