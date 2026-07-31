/* ============================================================
   LFS — Lusaka Fitness Squad
   js/shop.js (web root)
   
   Handles:
     • Filter sidebar mobile toggle
     • Filter form auto-submit on radio change
     • Quick view modal (load product data inline)
     • Quick-add to cart (AJAX)
     • Cart badge + FAB count sync
     • Toast notifications
     • Quantity +/- controls in modal
   ============================================================ */

'use strict';

/* ════════════════════════════════════════════════════════════
   MOBILE FILTER SIDEBAR
   ════════════════════════════════════════════════════════════ */

function initFilterSidebar() {
  const toggle   = document.getElementById('js-filter-toggle');
  const sidebar  = document.getElementById('shop-filters');
  if (!toggle || !sidebar) return;

  // Overlay backdrop for mobile
  const overlay = document.createElement('div');
  overlay.className = 'shop-filters__overlay';
  overlay.style.cssText = `
    display:none; position:fixed; inset:0; z-index:499;
    background:rgba(0,0,0,0.5); backdrop-filter:blur(2px);
  `;
  document.body.appendChild(overlay);

  function openFilters() {
    sidebar.classList.add('is-open');
    overlay.style.display = 'block';
    toggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  function closeFilters() {
    sidebar.classList.remove('is-open');
    overlay.style.display = 'none';
    toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  toggle.addEventListener('click', () => {
    sidebar.classList.contains('is-open') ? closeFilters() : openFilters();
  });

  overlay.addEventListener('click', closeFilters);

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sidebar.classList.contains('is-open')) closeFilters();
  });
}

/* ════════════════════════════════════════════════════════════
   AUTO-SUBMIT FILTERS
   Submit form whenever a radio or select changes (desktop UX).
   On mobile, the user presses "Apply Filters" button instead.
   ════════════════════════════════════════════════════════════ */

function initAutoSubmitFilters() {
  const form = document.getElementById('shop-filter-form');
  if (!form) return;

  const isMobile = () => window.innerWidth <= 900;

  form.querySelectorAll('input[type="radio"], select').forEach((input) => {
    input.addEventListener('change', () => {
      if (!isMobile()) {
        // Reset page to 1 on filter change
        const pageInput = document.getElementById('filter-page');
        if (pageInput) pageInput.value = 1;
        form.submit();
      }
    });
  });
}

/* ════════════════════════════════════════════════════════════
   CLEAR ALL FILTERS
   ════════════════════════════════════════════════════════════ */

function initClearFilters() {
  document.querySelector('.js-clear-filters')?.addEventListener('click', () => {
    window.location.href = '/shop';
  });
}

/* ════════════════════════════════════════════════════════════
   SIZE CHIP VISUAL TOGGLE (tailwind-style radio)
   ════════════════════════════════════════════════════════════ */

function initSizeChips() {
  document.querySelectorAll('.shop-filters__size-chip').forEach((chip) => {
    const radio = chip.querySelector('input[type="radio"]');
    if (!radio) return;

    radio.addEventListener('change', () => {
      // De-activate siblings
      chip.closest('.shop-filters__size-grid')
        ?.querySelectorAll('.shop-filters__size-chip')
        .forEach((c) => c.classList.remove('is-active'));
      chip.classList.add('is-active');
    });
  });
}

/* ════════════════════════════════════════════════════════════
   QUICK VIEW MODAL
   ════════════════════════════════════════════════════════════ */

function initQuickViewModal() {
  const modal   = document.getElementById('quick-view-modal');
  const content = document.getElementById('quick-view-content');
  if (!modal || !content) return;

  function openModal() {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    // Focus trap — focus close button
    modal.querySelector('.js-modal-close')?.focus();
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // Close triggers
  modal.querySelectorAll('.js-modal-close').forEach((btn) => {
    btn.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });

  /** HTML-escape a value before injecting into innerHTML or an attribute. */
  function safe(str) {
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
  }

  /**
   * Build quick-view HTML from product card data attributes.
   * For a production site, replace this with a fetch to a /shop/product/:slug?partial=1 endpoint.
   */
  function renderQuickView(card) {
    const productId = card.dataset.productId;
    const slug      = card.dataset.slug;
    const name      = card.querySelector('.product-card__name')?.textContent.trim() || '';
    const priceText = card.querySelector('.product-card__price--current')?.textContent.trim() || '';
    const imgSrc    = card.querySelector('.product-card__img')?.src || '/images/products/placeholder.webp';
    const imgAlt    = card.querySelector('.product-card__img')?.alt || name;
    const sizesRaw   = card.querySelector('.js-quick-add')?.dataset.sizes;
    const totalStock = parseInt(card.dataset.totalStock, 10) || 20;

    let sizes = [];
    try { sizes = sizesRaw ? JSON.parse(sizesRaw) : []; } catch (_) {}

    const sizeButtons = sizes.length > 0
      ? sizes.map((s, i) => `
          <button
            type="button"
            class="quick-view__size-btn ${i === 0 ? 'is-selected' : ''}"
            data-size="${safe(s)}"
            aria-label="Select size ${safe(s)}"
            aria-pressed="${i === 0}"
          >${safe(s)}</button>
        `).join('')
      : '<p style="font-size:0.85rem;color:#888;">One size</p>';

    content.innerHTML = `
      <div class="quick-view-grid">
        <div>
          <img src="${safe(imgSrc)}" alt="${safe(imgAlt)}" class="quick-view__img" width="480" height="480">
        </div>
        <div>
          <h2 class="quick-view__name">${safe(name)}</h2>
          <p class="quick-view__price">${safe(priceText)}</p>

          <div class="quick-view__size-label">Select Size</div>
          <div class="quick-view__sizes" id="qv-sizes" role="group" aria-label="Select size">
            ${sizeButtons}
          </div>

          <div class="quick-view__size-label" style="margin-top:0.25rem;">Quantity</div>
          <div class="quick-view__qty" aria-label="Quantity selector">
            <button type="button" class="quick-view__qty-btn" id="qv-minus" aria-label="Decrease quantity">−</button>
            <input type="number" class="quick-view__qty-input" id="qv-qty" value="1" min="1" max="${totalStock}" aria-label="Quantity">
            <button type="button" class="quick-view__qty-btn" id="qv-plus" aria-label="Increase quantity">+</button>
          </div>

          <button
            type="button"
            class="btn btn-primary quick-view__add-btn"
            id="qv-add-cart"
            data-product-id="${safe(productId)}"
            data-slug="${safe(slug)}"
            aria-label="Add to cart"
          >
            <i class="fas fa-shopping-bag" aria-hidden="true"></i>
            Add to Cart
          </button>

          <a href="/shop/product/${safe(slug)}" class="btn btn-outline mt-3" style="width:100%;justify-content:center;margin-top:0.75rem;">
            View Full Details
          </a>
        </div>
      </div>
    `;

    // Wire up size selection
    content.querySelectorAll('.quick-view__size-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        content.querySelectorAll('.quick-view__size-btn').forEach((b) => {
          b.classList.remove('is-selected');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('is-selected');
        btn.setAttribute('aria-pressed', 'true');
      });
    });

    // Qty +/−
    const qtyInput = content.querySelector('#qv-qty');
    content.querySelector('#qv-minus')?.addEventListener('click', () => {
      const v = parseInt(qtyInput.value, 10);
      if (v > 1) qtyInput.value = v - 1;
    });
    content.querySelector('#qv-plus')?.addEventListener('click', () => {
      const v = parseInt(qtyInput.value, 10);
      if (v < totalStock) qtyInput.value = v + 1;
    });

    // Add to cart from modal
    content.querySelector('#qv-add-cart')?.addEventListener('click', () => {
      const selectedSize = content.querySelector('.quick-view__size-btn.is-selected')?.dataset.size
        || (sizes.length === 0 ? 'One Size' : null);

      if (!selectedSize) {
        showToast('Please select a size first.', 'orange');
        return;
      }

      addToCartById(productId, selectedSize, parseInt(qtyInput.value, 10) || 1);
      closeModal();
    });
  }

  // Open modal on Quick View button click
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.product-card__quick-view');
    if (!btn) return;

    const card = btn.closest('.product-card');
    if (!card) return;

    // Reset content
    content.innerHTML = `
      <div class="shop-modal__loading" aria-label="Loading">
        <span class="live-dot"></span>
        <span class="live-dot" style="animation-delay:0.15s"></span>
        <span class="live-dot" style="animation-delay:0.3s"></span>
      </div>`;

    openModal();

    // Small delay so modal opens visibly before rendering
    requestAnimationFrame(() => renderQuickView(card));
  });
}

/* ════════════════════════════════════════════════════════════
   QUICK ADD (from product card "Add to Cart" button)
   Opens quick view if multiple sizes; otherwise uses first size.
   ════════════════════════════════════════════════════════════ */

function initQuickAdd() {
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.js-quick-add');
    if (!btn) return;

    const sizesRaw = btn.dataset.sizes;
    let sizes = [];
    try { sizes = sizesRaw ? JSON.parse(sizesRaw) : []; } catch (_) {}

    if (sizes.length > 1) {
      // Trigger quick view — user needs to pick a size
      const card    = btn.closest('.product-card');
      const qvBtn   = card?.querySelector('.product-card__quick-view');
      qvBtn?.click();
      return;
    }

    // Single or no size — add directly
    const productId = btn.dataset.productId;
    const size      = sizes[0] || 'One Size';
    addToCartById(productId, size, 1);
  });
}


/* ════════════════════════════════════════════════════════════
   SCROLL-REVEAL (reuse main.js pattern if not already running)
   ════════════════════════════════════════════════════════════ */

function initScrollReveal() {
  if (typeof IntersectionObserver === 'undefined') return;

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('revealed');
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.08 }
  );

  document.querySelectorAll('.product-card').forEach((card, i) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    card.style.transition = `opacity 0.5s ease ${i * 0.05}s, transform 0.5s ease ${i * 0.05}s`;
    observer.observe(card);
  });

  // Trigger on first paint for already-visible cards
  document.querySelectorAll('.product-card').forEach((card) => {
    const rect = card.getBoundingClientRect();
    if (rect.top < window.innerHeight) {
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }
  });
}

/* ════════════════════════════════════════════════════════════
   INIT
   ════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', () => {
  initFilterSidebar();
  initAutoSubmitFilters();
  initClearFilters();
  initSizeChips();
  initQuickViewModal();
  initQuickAdd();
  initScrollReveal();
});
