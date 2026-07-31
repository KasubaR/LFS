/* ============================================================
   LFS — Lusaka Fitness Squad
   js/productDetails.js (web root)

   Handles:
     • Image gallery — thumbnail click + main image swap
     • Image zoom on hover (CSS-driven, JS manages data-zoom-src)
     • Quantity increment / decrement with per-size stock limits
     • Size selector (radio-style buttons)
     • Add to cart (AJAX POST to /shop/cart/add)
     • Buy Now (add to cart then redirect to /shop/cart)
     • Toast notification with action links
     • Size guide modal open / close
     • Tab panel switching (Description / Details / Size Guide)
     • Cart badge / FAB count sync
     • Scroll-reveal for related products
   ============================================================ */

'use strict';


function initToastDismiss() {
  document.getElementById('pd-toast-dismiss')?.addEventListener('click', () => {
    const toast = document.getElementById('cart-toast');
    if (toast) {
      clearTimeout(toast._timeout);
      toast.classList.remove('show');
    }
  });
}

/* ════════════════════════════════════════════════════════════
   IMAGE GALLERY
   ════════════════════════════════════════════════════════════ */

function initGallery() {
  const mainImg   = document.getElementById('pd-main-img');
  const thumbsWrap = document.querySelector('.pd-gallery__thumbs');
  if (!mainImg) return;

  const thumbBtns = document.querySelectorAll('.pd-gallery__thumb');

  function setActive(btn) {
    thumbBtns.forEach((t) => {
      t.classList.remove('is-active');
      t.setAttribute('aria-pressed', 'false');
    });
    btn.classList.add('is-active');
    btn.setAttribute('aria-pressed', 'true');

    const src = btn.dataset.src;
    mainImg.src = src;
    mainImg.setAttribute('data-zoom-src', src);

    // Scroll thumb into view if out of viewport
    btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
  }

  thumbBtns.forEach((btn) => {
    btn.addEventListener('click', () => setActive(btn));
  });

  // Keyboard navigation for thumbnails
  if (thumbsWrap) {
    thumbsWrap.addEventListener('keydown', (e) => {
      const current   = thumbsWrap.querySelector('.pd-gallery__thumb.is-active');
      const allThumbs = [...thumbBtns];
      const idx       = allThumbs.indexOf(current);

      if (e.key === 'ArrowRight' && idx < allThumbs.length - 1) {
        e.preventDefault();
        setActive(allThumbs[idx + 1]);
        allThumbs[idx + 1].focus();
      } else if (e.key === 'ArrowLeft' && idx > 0) {
        e.preventDefault();
        setActive(allThumbs[idx - 1]);
        allThumbs[idx - 1].focus();
      }
    });
  }
}

/* ════════════════════════════════════════════════════════════
   SIZE SELECTOR
   ════════════════════════════════════════════════════════════ */

function initSizeSelector() {
  const sizeGroup   = document.getElementById('pd-size-group');
  const sizeBtns    = document.querySelectorAll('.pd-size-btn');
  const hiddenInput = document.getElementById('pd-selected-size');
  const sizeError   = document.getElementById('pd-size-error');
  const qtyInput    = document.getElementById('pd-qty');

  if (!sizeBtns.length) return;

  function clearError() {
    if (sizeError) sizeError.hidden = true;
    sizeGroup?.classList.remove('has-error');
  }

  sizeBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (btn.disabled) return;

      sizeBtns.forEach((b) => {
        b.classList.remove('is-selected');
        b.setAttribute('aria-pressed', 'false');
      });

      btn.classList.add('is-selected');
      btn.setAttribute('aria-pressed', 'true');

      if (hiddenInput) hiddenInput.value = btn.dataset.size;
      clearError();

      // Update max qty for this size
      if (qtyInput) {
        const stock = parseInt(btn.dataset.stock, 10) || 1;
        qtyInput.max = stock;
        if (parseInt(qtyInput.value, 10) > stock) {
          qtyInput.value = stock;
        }
        // Re-evaluate +/- buttons
        updateQtyButtons();
      }
    });
  });
}

/* ════════════════════════════════════════════════════════════
   QUANTITY SELECTOR
   ════════════════════════════════════════════════════════════ */

function updateQtyButtons() {
  const input    = document.getElementById('pd-qty');
  const minusBtn = document.getElementById('pd-qty-minus');
  const plusBtn  = document.getElementById('pd-qty-plus');
  if (!input) return;

  const val = parseInt(input.value, 10) || 1;
  const max = parseInt(input.max, 10) || 99;
  const min = parseInt(input.min, 10) || 1;

  if (minusBtn) minusBtn.disabled = val <= min;
  if (plusBtn)  plusBtn.disabled  = val >= max;
}

function initQtySelector() {
  const input    = document.getElementById('pd-qty');
  const minusBtn = document.getElementById('pd-qty-minus');
  const plusBtn  = document.getElementById('pd-qty-plus');
  if (!input) return;

  minusBtn?.addEventListener('click', () => {
    const min = parseInt(input.min, 10) || 1;
    const val = parseInt(input.value, 10) || 1;
    if (val > min) {
      input.value = val - 1;
      updateQtyButtons();
    }
  });

  plusBtn?.addEventListener('click', () => {
    const max = parseInt(input.max, 10) || 99;
    const val = parseInt(input.value, 10) || 1;
    if (val < max) {
      input.value = val + 1;
      updateQtyButtons();
    }
  });

  input.addEventListener('change', () => {
    const min = parseInt(input.min, 10) || 1;
    const max = parseInt(input.max, 10) || 99;
    let   val = parseInt(input.value, 10);
    if (isNaN(val) || val < min) val = min;
    if (val > max)               val = max;
    input.value = val;
    updateQtyButtons();
  });

  updateQtyButtons();
}


/* ════════════════════════════════════════════════════════════
   PURCHASE FORM SUBMISSION
   ════════════════════════════════════════════════════════════ */

function initPurchaseForm() {
  const form       = document.getElementById('pd-purchase-form');
  if (!form) return;

  const productId  = form.dataset.productId;
  const sizeInput  = document.getElementById('pd-selected-size');
  const qtyInput   = document.getElementById('pd-qty');
  const sizeError  = document.getElementById('pd-size-error');
  const sizeGroup  = document.getElementById('pd-size-group');

  function validate() {
    // Size check — skip if there are no size buttons (one-size product)
    const hasSizeSelector = document.querySelectorAll('.pd-size-btn').length > 0;
    if (hasSizeSelector && !sizeInput?.value) {
      if (sizeError) sizeError.hidden = false;
      sizeGroup?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      sizeGroup?.classList.add('has-error');

      // Shake animation
      sizeGroup?.animate(
        [
          { transform: 'translateX(0)' },
          { transform: 'translateX(-5px)' },
          { transform: 'translateX(5px)' },
          { transform: 'translateX(-5px)' },
          { transform: 'translateX(0)' },
        ],
        { duration: 350, easing: 'ease-in-out' }
      );

      return false;
    }
    return true;
  }

  // Add to cart
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validate()) return;

    const size = sizeInput?.value || 'One Size';
    const qty  = parseInt(qtyInput?.value, 10) || 1;

    await addToCartById(productId, size, qty, false);
  });

  // Buy Now
  document.getElementById('pd-buy-now')?.addEventListener('click', async () => {
    if (!validate()) return;

    const size = sizeInput?.value || 'One Size';
    const qty  = parseInt(qtyInput?.value, 10) || 1;

    await addToCartById(productId, size, qty, true);
  });
}

/* ════════════════════════════════════════════════════════════
   TABS
   ════════════════════════════════════════════════════════════ */

function initTabs() {
  const tabs   = document.querySelectorAll('.pd-tabs__tab');
  const panels = document.querySelectorAll('.pd-tabs__panel');
  if (!tabs.length) return;

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const target = tab.getAttribute('aria-controls');

      tabs.forEach((t) => {
        t.classList.remove('is-active');
        t.setAttribute('aria-selected', 'false');
      });

      panels.forEach((p) => {
        p.classList.remove('is-active');
        p.hidden = true;
      });

      tab.classList.add('is-active');
      tab.setAttribute('aria-selected', 'true');

      const targetPanel = document.getElementById(target);
      if (targetPanel) {
        targetPanel.classList.add('is-active');
        targetPanel.hidden = false;
      }
    });
  });
}

/* ════════════════════════════════════════════════════════════
   SIZE GUIDE MODAL
   ════════════════════════════════════════════════════════════ */

function initSizeGuideModal() {
  const modal    = document.getElementById('size-guide-modal');
  const openBtns = document.querySelectorAll('.pd-form__size-guide, [data-open="size-guide"]');
  const closeBtns = document.querySelectorAll('.js-sg-close');
  if (!modal) return;

  function open() {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    modal.querySelector('.pd-modal__close')?.focus();

    // Also open the sizing tab in the tabs section (below the fold)
    const sizingTabBtn = document.getElementById('btn-sizing');
    if (sizingTabBtn) sizingTabBtn.click();
  }

  function close() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  openBtns.forEach((btn) => btn.addEventListener('click', open));
  closeBtns.forEach((btn) => btn.addEventListener('click', close));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
  });
}

/* ════════════════════════════════════════════════════════════
   SCROLL REVEAL (related products + tabs)
   ════════════════════════════════════════════════════════════ */

function initScrollReveal() {
  const items = document.querySelectorAll('[data-reveal]');
  if (!items.length) return;

  if (!document.getElementById('lfs-reveal-style')) {
    const style = document.createElement('style');
    style.id = 'lfs-reveal-style';
    style.textContent = '[data-reveal].revealed { opacity: 1 !important; transform: translateY(0) !important; }';
    document.head.appendChild(style);
  }

  if (!('IntersectionObserver' in window)) return;

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('revealed');
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.08, rootMargin: '0px 0px -40px 0px' }
  );

  items.forEach((el, i) => {
    el.style.opacity  = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = `opacity 0.55s ease ${i * 0.08}s, transform 0.55s ease ${i * 0.08}s`;
    observer.observe(el);
  });
}

/* ════════════════════════════════════════════════════════════
   IMAGE ZOOM (lightbox-lite)
   A minimal CSS-driven zoom: clicking main image opens a
   full-screen overlay with the full-resolution version.
   ════════════════════════════════════════════════════════════ */

function initImageZoom() {
  const mainWrap = document.getElementById('pd-main-img-wrap');
  const mainImg  = document.getElementById('pd-main-img');
  if (!mainWrap || !mainImg) return;

  // Create overlay element once
  const overlay = document.createElement('div');
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Zoomed product image');
  overlay.style.cssText = `
    display:none; position:fixed; inset:0; z-index:2000;
    background:rgba(0,0,0,0.92); cursor:zoom-out;
    align-items:center; justify-content:center;
    backdrop-filter:blur(6px);
  `;

  const zoomedImg = document.createElement('img');
  zoomedImg.style.cssText = `
    max-width:90vw; max-height:90vh;
    object-fit:contain; border-radius:8px;
    box-shadow:0 24px 80px rgba(0,0,0,0.6);
    transition:transform 0.25s ease;
  `;
  zoomedImg.alt = mainImg.alt;

  const closeBtn = document.createElement('button');
  closeBtn.textContent = '×';
  closeBtn.setAttribute('aria-label', 'Close zoomed image');
  closeBtn.style.cssText = `
    position:absolute; top:1.5rem; right:1.5rem;
    font-size:2rem; color:#fff; background:none; border:none;
    cursor:pointer; line-height:1; padding:0.25rem 0.75rem;
    border-radius:4px; transition:background 0.15s ease;
  `;
  closeBtn.onmouseenter = () => closeBtn.style.background = 'rgba(255,255,255,0.1)';
  closeBtn.onmouseleave = () => closeBtn.style.background = 'none';

  overlay.appendChild(zoomedImg);
  overlay.appendChild(closeBtn);
  document.body.appendChild(overlay);

  function openZoom() {
    zoomedImg.src   = mainImg.dataset.zoomSrc || mainImg.src;
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    closeBtn.focus();
  }

  function closeZoom() {
    overlay.style.display = 'none';
    document.body.style.overflow = '';
    mainWrap.focus();
  }

  mainWrap.addEventListener('click', openZoom);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeZoom(); });
  closeBtn.addEventListener('click', closeZoom);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && overlay.style.display !== 'none') closeZoom();
    // Arrow keys to browse images
    if (overlay.style.display === 'flex') {
      const thumbs = [...document.querySelectorAll('.pd-gallery__thumb')];
      const active = document.querySelector('.pd-gallery__thumb.is-active');
      const idx    = thumbs.indexOf(active);

      if (e.key === 'ArrowRight' && idx < thumbs.length - 1) {
        thumbs[idx + 1].click();
        zoomedImg.src = thumbs[idx + 1].dataset.src;
      } else if (e.key === 'ArrowLeft' && idx > 0) {
        thumbs[idx - 1].click();
        zoomedImg.src = thumbs[idx - 1].dataset.src;
      }
    }
  });
}

/* ════════════════════════════════════════════════════════════
   INIT — run all modules on DOM ready
   ════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', () => {
  initGallery();
  initSizeSelector();
  initQtySelector();
  initPurchaseForm();
  initTabs();
  initSizeGuideModal();
  initToastDismiss();
  initScrollReveal();
  initImageZoom();
});
