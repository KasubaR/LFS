/* ============================================================
   LFS — Lusaka Fitness Squad | cart.js
   Server-backed cart — overrides main.js placeholder functions.
   Loaded globally after main.js (see layouts/main.ejs).
   ============================================================ */

'use strict';

/* ─────────────────────────────────────────────────────────────
   TOAST  (global — shared by shop.js, productDetails.js, etc.)
───────────────────────────────────────────────────────────── */

/**
 * Show the global toast notification.
 * @param {string} msg
 * @param {'green'|'red'|'orange'|'default'} [type='green']
 * @param {number} [duration=3000]
 */
window.showToast = function showToast(msg, type = 'green', duration = 3000) {
  const toast = document.getElementById('cart-toast');
  const label = document.getElementById('cart-toast-msg');
  if (!toast || !label) return;

  label.textContent = msg;
  toast.className   = `lfs-toast show${type !== 'green' && type !== 'default' ? ' ' + type : ''}`;

  clearTimeout(toast._timeout);
  toast._timeout = setTimeout(() => toast.classList.remove('show'), duration);
};

/* ─────────────────────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────────────────────── */

/** Update all cart badge/count indicators on the page. */
function updateCartBadge(count) {
  const badge = document.querySelector('.lfs-cart-fab__count');
  if (badge) {
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
  }

  const fab = document.querySelector('.lfs-cart-fab');
  if (fab) {
    fab.setAttribute('aria-label', `View cart (${count} item${count !== 1 ? 's' : ''})`);
    if (count > 0) {
      fab.classList.remove('lfs-cart-fab--hidden');
    } else {
      fab.classList.add('lfs-cart-fab--hidden');
    }
  }

  // Navbar / inline count elements
  document.querySelectorAll('[data-cart-count]').forEach((el) => {
    el.textContent = count;
    el.style.display = count > 0 ? '' : 'none';
  });
}

/** Read lfs_csrf cookie value for CSRF header. */
function getCsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)lfs_csrf=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}

/** POST JSON to a cart endpoint. Returns parsed response or null on network error. */
async function cartFetch(url, body) {
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept':        'application/json',
        'X-CSRF-Token':  getCsrfToken(),
      },
      body: JSON.stringify(body),
    });
    return res.json();
  } catch {
    return null;
  }
}

/* ─────────────────────────────────────────────────────────────
   ADD TO CART BY ID
   Shared by shop.js (quick-view) and productDetails.js.
   Centralised here so neither file re-declares a global.

   @param {string}  productId
   @param {string}  size
   @param {number}  qty
   @param {boolean} [redirect=false]  navigate to /shop/cart on success
   @returns {Promise<boolean>}
───────────────────────────────────────────────────────────── */
window.addToCartById = async function addToCartById(productId, size, qty, redirect = false) {
  const addBtn = document.getElementById('pd-add-cart');
  if (addBtn) addBtn.classList.add('is-loading');

  try {
    const headers = {
      'Content-Type':     'application/json',
      'Accept':           'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };
    const csrf = getCsrfToken();
    if (csrf) headers['X-CSRF-Token'] = csrf;

    const res = await fetch('/shop/cart/add', {
      method:      'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify({ productId, size, qty }),
    });

    const contentType = res.headers.get('Content-Type') || '';
    const text        = await res.text();
    let data = null;
    if (contentType.includes('application/json') && text) {
      try { data = JSON.parse(text); } catch (_) {}
    }

    if (data && typeof data.ok !== 'undefined') {
      if (data.ok) {
        updateCartBadge(data.itemCount);
        if (redirect) { window.location.href = '/shop/cart'; return true; }
        const name = document.querySelector('.pd-info__name')?.textContent?.trim() || 'Item';
        if (window.showToast) window.showToast(`✓ ${name} added — ${data.subtotal}`, 'green');
        return true;
      }
      if (window.showToast) window.showToast(data.message || 'Could not add to cart.', 'red');
      return false;
    }

    if (!res.ok) {
      const msg = res.status === 403
        ? 'Session expired. Please refresh and try again.'
        : 'Something went wrong. Please try again.';
      if (window.showToast) window.showToast(msg, 'red');
      return false;
    }

    if (window.showToast) window.showToast('Unexpected response. Please try again.', 'red');
    return false;

  } catch (err) {
    console.error('[LFS] addToCartById error:', err);
    if (window.showToast) window.showToast('Network error. Please try again.', 'red');
    return false;
  } finally {
    if (addBtn) addBtn.classList.remove('is-loading');
  }
};

/* ─────────────────────────────────────────────────────────────
   OVERRIDE: viewCart — navigate to cart page instead of alert
───────────────────────────────────────────────────────────── */
window.viewCart = function () {
  window.location.href = '/shop/cart';
};

/* ─────────────────────────────────────────────────────────────
   OVERRIDE: addToCart — real server POST via fetch
   Called by main.js initCart() for .product-card__add buttons,
   and directly from product detail page form.
───────────────────────────────────────────────────────────── */
window.addToCart = async function (btn) {
  // Support both a plain button (main.js path) and a form submit button
  const form      = btn ? btn.closest('form') : null;
  const productId = form?.querySelector('[name="productId"]')?.value
                  || btn?.dataset?.productId;
  const sizeInput = form?.querySelector('input[name="size"]:checked')
                  || form?.querySelector('select[name="size"]')
                  || form?.querySelector('[name="size"]');
  const size      = sizeInput?.value
                  || form?.querySelector('[data-selected-size]')?.dataset?.selectedSize
                  || btn?.dataset?.size;
  const qty       = Number(form?.querySelector('[name="qty"]')?.value || 1);

  if (!productId) return;

  if (!size) {
    if (window.showToast) window.showToast('Please select a size.', 'orange', 2500);
    return;
  }

  // Visual feedback
  const originalHTML = btn ? btn.innerHTML : '';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  }

  const data = await cartFetch('/shop/cart/add', { productId, size, qty });

  if (data && data.ok) {
    if (btn) btn.innerHTML = '<i class="fas fa-check"></i> Added';
    updateCartBadge(data.itemCount);
    if (window.showToast) window.showToast('Item added to cart!', 'default', 2500);
  } else {
    const msg = data?.message || 'Could not add item. Please try again.';
    if (btn) btn.innerHTML = originalHTML;
    if (window.showToast) window.showToast(msg, 'red', 3000);
  }

  // Reset button after a short delay
  if (btn) {
    setTimeout(() => {
      btn.innerHTML = originalHTML;
      btn.classList.remove('added');
      btn.disabled = false;
    }, 1600);
  }
};

/* ─────────────────────────────────────────────────────────────
   CART PAGE — inline quantity updates + remove
───────────────────────────────────────────────────────────── */

/** Reformat a price as "K X,XXX" */
function formatKwacha(amount) {
  return 'K\u00a0' + Math.round(amount).toLocaleString();
}

/** Replace the entire cart layout with an empty-state message. */
function showEmptyState() {
  const layout = document.querySelector('.cart-layout');
  if (!layout) return;
  layout.outerHTML = `
    <div class="cart-empty">
      <div class="cart-empty__icon" aria-hidden="true"><i class="fas fa-shopping-bag"></i></div>
      <h2 class="cart-empty__title">Your cart is empty</h2>
      <p class="cart-empty__desc">Browse our official LFS running gear and regalia.</p>
      <a href="/shop" class="btn btn-primary cart-empty__btn">
        <i class="fas fa-store" aria-hidden="true"></i>Browse Shop
      </a>
    </div>`;
  // Update header count badge
  const headerCount = document.querySelector('.cart-header__count');
  if (headerCount) headerCount.remove();
}

/** Wire up cart-page interactions (qty stepper + remove). */
function initCartPage() {
  const itemsEl = document.querySelector('.cart-items');
  if (!itemsEl) return;

  /* ── Qty input change ── */
  itemsEl.addEventListener('change', async (e) => {
    const input = e.target.closest('.cart-item__qty-input');
    if (!input) return;

    const key  = input.dataset.key;
    const qty  = Math.max(0, parseInt(input.value, 10) || 0);
    const row  = input.closest('.cart-item');

    const data = await cartFetch('/shop/cart/update', { key, qty });
    if (!data) return;

    updateCartBadge(data.itemCount);

    if (qty <= 0) {
      row?.remove();
    } else {
      const unitPrice    = parseFloat(row?.dataset?.price || '0');
      const lineTotalEl  = row?.querySelector('.cart-item__line-total');
      if (lineTotalEl) lineTotalEl.textContent = formatKwacha(unitPrice * qty);
    }

    // Update both subtotal displays (summary + total row reuse same class)
    const subtotalEls = document.querySelectorAll('.cart-summary__subtotal');
    subtotalEls.forEach(el => { el.textContent = data.subtotal; });

    if (data.itemCount === 0) showEmptyState();
  });

  /* ── Qty stepper buttons ── */
  itemsEl.addEventListener('click', (e) => {
    const stepBtn = e.target.closest('[data-step]');
    if (!stepBtn) return;
    const input = stepBtn.closest('.cart-item__qty')?.querySelector('.cart-item__qty-input');
    if (!input) return;
    const newQty = Math.max(0, parseInt(input.value, 10) + parseInt(stepBtn.dataset.step, 10));
    input.value = newQty;
    input.dispatchEvent(new Event('change'));
  });

  /* ── Remove button ── */
  itemsEl.addEventListener('click', async (e) => {
    const removeBtn = e.target.closest('[data-remove-key]');
    if (!removeBtn) return;

    const key = removeBtn.dataset.removeKey;
    const row = removeBtn.closest('.cart-item');

    removeBtn.disabled = true;
    removeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const data = await cartFetch('/shop/cart/remove', { key });
    if (data) {
      row?.remove();
      updateCartBadge(data.itemCount);
      const subtotalEls = document.querySelectorAll('.cart-summary__subtotal');
      subtotalEls.forEach(el => { el.textContent = data.subtotal; });
      if (data.itemCount === 0) showEmptyState();
    } else {
      removeBtn.disabled = false;
      removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    }
  });
}

/* ─────────────────────────────────────────────────────────────
   PRODUCT DETAIL PAGE — "Add to Cart" form submit
   The form on productDetails.ejs should have data-cart-form.
───────────────────────────────────────────────────────────── */
function initAddToCartForm() {
  const forms = document.querySelectorAll('form[data-cart-form]');
  forms.forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type="submit"]');
      await window.addToCart(btn);
    });
  });
}

/* ─────────────────────────────────────────────────────────────
   BOOT
───────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  // Sync badge with server-rendered count
  updateCartBadge(window.__LFS_CART_COUNT__ || 0);

  // Cart page interactions
  initCartPage();

  // Product detail add-to-cart form
  initAddToCartForm();
});
