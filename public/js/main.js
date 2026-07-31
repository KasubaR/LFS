/* ============================================================
   LFS — Lusaka Fitness Squad | main.js
   Global JavaScript — nav, scroll, cart, forms, animations.
   ============================================================ */

'use strict';

/* ─────────────────────────────────────────────────────────────
   1. CONSTANTS & STATE
───────────────────────────────────────────────────────────── */
const LFS = {
  cart: {
    get items() { return JSON.parse(localStorage.getItem('lfs_cart') || '[]'); },
    set items(v) { localStorage.setItem('lfs_cart', JSON.stringify(v)); },
    get count() { return this.items.length; },
  },
  state: {
    navOpen: false,
    scrolled: false,
  },
};

/* ─────────────────────────────────────────────────────────────
   2. UTILITY HELPERS
───────────────────────────────────────────────────────────── */

/**
 * Shorthand querySelector
 * @param {string} selector
 * @param {Document|Element} [ctx=document]
 */
const $ = (selector, ctx = document) => ctx.querySelector(selector);

/**
 * Shorthand querySelectorAll — returns array
 * @param {string} selector
 * @param {Document|Element} [ctx=document]
 */
const $$ = (selector, ctx = document) => [...ctx.querySelectorAll(selector)];

/**
 * Open or close the mobile nav drawer. Hoisted so initNav and initA11y both use it.
 * @param {boolean} open
 */
function setMobileNavOpen(open) {
  const hamburger = $('.lfs-nav__hamburger');
  const mobileMenu = $('.lfs-nav__mobile');
  LFS.state.navOpen = open;
  hamburger?.classList.toggle('open', open);
  hamburger?.setAttribute('aria-expanded', open ? 'true' : 'false');
  mobileMenu?.classList.toggle('open', open);
  mobileMenu?.setAttribute('aria-hidden', open ? 'false' : 'true');
  if (open) {
    LFS.state.scrollY = window.scrollY;
    document.documentElement.style.setProperty('--scroll-y', window.scrollY + 'px');
    document.body.classList.add('mobile-nav-open');
  } else {
    document.body.classList.remove('mobile-nav-open');
    if (typeof LFS.state.scrollY === 'number') {
      window.scrollTo(0, LFS.state.scrollY);
    }
  }
}

/**
 * Show a toast notification
 * @param {string} message
 * @param {'default'|'red'|'orange'} [type='default']
 * @param {number} [duration=3000]
 */
function showToast(message, type = 'default', duration = 3000) {
  // Create if doesn't exist
  let toast = $('#lfs-toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'lfs-toast';
    toast.className = 'lfs-toast';
    document.body.appendChild(toast);
  }

  // Reset classes, set type
  toast.className = 'lfs-toast';
  if (type !== 'default') toast.classList.add(type);

  toast.textContent = message;
  // Trigger reflow then animate in
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      toast.classList.add('show');
    });
  });

  clearTimeout(toast._timeout);
  toast._timeout = setTimeout(() => {
    toast.classList.remove('show');
  }, duration);
}

/* ─────────────────────────────────────────────────────────────
   3. NAVIGATION
───────────────────────────────────────────────────────────── */
function initNav() {
  const nav         = $('.lfs-nav') || $('nav');
  const hamburger   = $('.lfs-nav__hamburger');
  const mobileMenu  = $('.lfs-nav__mobile');

  if (!nav) return;

  function syncNavHeight() {
    document.documentElement.style.setProperty('--nav-height', nav.offsetHeight + 'px');
  }
  syncNavHeight();

  // Scroll shrink
  window.addEventListener('scroll', () => {
    const scrolled = window.scrollY > 60;
    if (scrolled !== LFS.state.scrolled) {
      LFS.state.scrolled = scrolled;
      nav.classList.toggle('scrolled', scrolled);
      syncNavHeight();
    }
  }, { passive: true });

  // Hamburger toggle
  if (hamburger && mobileMenu) {
    hamburger.addEventListener('click', () => {
      setMobileNavOpen(!LFS.state.navOpen);
      closeNavProfileMenu();
    });
  }

  // Profile dropdown (signed-in account menu)
  const profile = $('.lfs-nav__profile');
  const profileToggle = $('.lfs-nav__profile-toggle');
  const profileMenu = $('#nav-profile-menu');

  function closeNavProfileMenu() {
    if (!profile || !profileToggle || !profileMenu) return;
    profile.classList.remove('is-open');
    profileToggle.setAttribute('aria-expanded', 'false');
    profileMenu.hidden = true;
  }

  function openNavProfileMenu() {
    if (!profile || !profileToggle || !profileMenu) return;
    profile.classList.add('is-open');
    profileToggle.setAttribute('aria-expanded', 'true');
    profileMenu.hidden = false;
  }

  if (profile && profileToggle && profileMenu) {
    profileToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (profile.classList.contains('is-open')) {
        closeNavProfileMenu();
      } else {
        if (LFS.state.navOpen) setMobileNavOpen(false);
        openNavProfileMenu();
      }
    });

    document.addEventListener('click', (e) => {
      if (!profile.contains(e.target)) closeNavProfileMenu();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeNavProfileMenu();
    });
  }

  // Close mobile menu on link click
  $$('a', mobileMenu || document).forEach(link => {
    link.addEventListener('click', () => {
      if (LFS.state.navOpen) setMobileNavOpen(false);
    });
  });

  // Smooth scroll for same-page anchor links
  $$('a[href*="#"]').forEach(link => {
    link.addEventListener('click', (e) => {
      const href = link.getAttribute('href') || '';
      const isSamePage = !href.startsWith('http') && (href.startsWith('#') || href.startsWith('/#'));
      const hashPart = href.split('#')[1];
      const target = hashPart ? document.getElementById(hashPart) : null;
      if (isSamePage && target) {
        e.preventDefault();
        const navHeight = nav.offsetHeight;
        const top = target.getBoundingClientRect().top + window.scrollY - navHeight;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    });
  });
}

/* ─────────────────────────────────────────────────────────────
   4. SCROLL-REVEAL OBSERVER
───────────────────────────────────────────────────────────── */
function initScrollReveal(root = document) {
  const revealEls = $$('[data-reveal]', root);
  if (!revealEls.length) return;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('revealed');
        observer.unobserve(entry.target); // fire once
      }
    });
  }, { threshold: 0.12 });

  revealEls.forEach(el => observer.observe(el));
}

/* ─────────────────────────────────────────────────────────────
   5. CART
───────────────────────────────────────────────────────────── */
function initCart() {
  // FAB button
  const fab = $('.lfs-cart-fab');
  if (fab) {
    fab.addEventListener('click', viewCart);
  }

  // Wire up any existing add buttons with class .product-card__add
  $$('.product-card__add').forEach(btn => {
    btn.addEventListener('click', () => addToCart(btn));
  });

  // Hydrate badge from localStorage so count survives page navigation
  const countEl = $('.lfs-cart-fab__count') ?? $('#cartCount');
  if (countEl) {
    const stored = LFS.cart.count;
    countEl.textContent = stored;
    countEl.style.display = stored > 0 ? 'flex' : 'none';
  }
}

/**
 * Add an item to the cart
 * @param {HTMLElement} btn — the add button element
 */
function addToCart(btn) {
  // Get product name from nearest card
  const card  = btn.closest('.product-card') || btn.closest('[class*="product"]');
  const name  = card ? (card.querySelector('.product-card__name, .font-bold')?.textContent?.trim() ?? 'Item') : 'Item';
  const price = card ? (card.querySelector('.product-card__price, .font-[\'Bebas_Neue\']')?.textContent?.trim() ?? '') : '';

  const items = LFS.cart.items;
  items.push({ name, price });
  LFS.cart.items = items;

  // Update FAB badge
  const countEl = $('.lfs-cart-fab__count') ?? $('#cartCount');
  if (countEl) {
    countEl.textContent = LFS.cart.count;
    countEl.style.display = 'flex';
  }

  // Visual feedback on button
  const icon = btn.querySelector('i') || btn;
  const original = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-check"></i>';
  btn.classList.add('added');
  btn.disabled = true;

  setTimeout(() => {
    btn.innerHTML = original;
    btn.classList.remove('added');
    btn.disabled = false;
  }, 1200);

  showToast(`${name} added to cart`, 'default', 2500);
}

/**
 * Render a non-blocking cart summary modal.
 */
function showCartModal(aggregated, count) {
  const backdrop = document.createElement('div');
  backdrop.className = 'lfs-cart-modal-backdrop';

  const plural = count > 1 ? 's' : '';
  const itemsHtml = aggregated
    .map(i => `<li>${i.name} &times; ${i.qty} &nbsp; ${i.price}</li>`)
    .join('');

  backdrop.innerHTML = `
    <div class="lfs-cart-modal" role="dialog" aria-modal="true" aria-label="Cart summary">
      <div class="lfs-cart-modal__header">
        <p class="lfs-cart-modal__title">&#x1F6D2; LFS Cart (${count} item${plural})</p>
        <button class="lfs-cart-modal__close" aria-label="Close cart">&times;</button>
      </div>
      <ul class="lfs-cart-modal__items">${itemsHtml}</ul>
      <div class="lfs-cart-modal__contact">
        To complete your purchase, contact:<br>
        LFS Treasurer: Mucha Dhlamini<br>
        &#x1F4DE; +260 962 333 651
      </div>
    </div>`;

  const close = () => backdrop.remove();
  backdrop.querySelector('.lfs-cart-modal__close').addEventListener('click', close);
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', function onKey(e) {
    if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
  });

  document.body.appendChild(backdrop);
  backdrop.querySelector('.lfs-cart-modal__close').focus();
}

/**
 * Show cart summary
 */
function viewCart() {
  if (LFS.cart.count === 0) {
    showToast('Your cart is empty. Browse our official LFS regalia!', 'orange', 3000);
    return;
  }

  const aggregated = LFS.cart.items.reduce((acc, item) => {
    const existing = acc.find(i => i.name === item.name);
    if (existing) {
      existing.qty++;
    } else {
      acc.push({ name: item.name, price: item.price, qty: 1 });
    }
    return acc;
  }, []);

  showCartModal(aggregated, LFS.cart.count);
}

/* ─────────────────────────────────────────────────────────────
   6. CONTACT FORM
───────────────────────────────────────────────────────────── */
function initContactForm() {
  // `data-native-submit` on the form wrapper (contact-us.php) signals that this
  // form POSTs natively to /contact (src/routes/contact.php) and must not be
  // intercepted by JS. Do NOT remove without coordinating with the PHP route handler.
  const contactSection = $('section#contact');
  const hasNativeSubmit = contactSection?.querySelector('[data-native-submit]') !== null;
  if (hasNativeSubmit) return;

  const form     = $('.lfs-form:not([data-native-submit])') || $('section#contact form:not([data-native-submit])');
  const submitBtn = form
    ? form.querySelector('button[type="submit"], .btn-submit, button:last-of-type')
    : $('[onclick*="handleSubmit"]');

  if (submitBtn) {
    // Remove inline onclick if present
    submitBtn.removeAttribute('onclick');
    submitBtn.addEventListener('click', (e) => {
      e.preventDefault();
      contactHandleSubmit(submitBtn, form);
    });
  }
}

/**
 * Handle contact form submit
 * @param {HTMLElement} btn
 * @param {HTMLElement|null} form
 */
async function contactHandleSubmit(btn, form = null) {
  // Simple validation
  const inputs = form
    ? $$('input, textarea, select', form).filter(el => el.required && !el.value.trim())
    : [];

  if (inputs.length) {
    inputs[0].focus();
    showToast('Please fill in all required fields.', 'red', 3000);
    return;
  }

  if (form && typeof window.LFSInputSanitizer !== 'undefined') {
    window.LFSInputSanitizer.sanitizeContactForm(form);
  }

  const originalHTML = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Sending…';
  btn.disabled = true;

  try {
    const res = await fetch(form.action || '/contact', {
      method: 'POST',
      body: new FormData(form),
    });

    if (res.ok) {
      btn.innerHTML = '<i class="fas fa-check mr-2"></i> Message Sent!';
      btn.style.background = 'var(--dark-green)';
      showToast("Your message has been sent. We'll be in touch!", 'default', 4000);
      setTimeout(() => {
        btn.innerHTML = originalHTML;
        btn.style.background = '';
        btn.disabled = false;
        form.reset();
      }, 2500);
    } else {
      throw new Error('Server error');
    }
  } catch {
    btn.innerHTML = originalHTML;
    btn.disabled = false;
    showToast('Could not send message. Please try again.', 'red', 4000);
  }
}

/* ─────────────────────────────────────────────────────────────
   7. ACTIVE NAV LINK ON SCROLL
───────────────────────────────────────────────────────────── */
function initActiveLinks() {
  const sections = $$('section[id]');
  const navLinks = $$('.lfs-nav__links a, nav ul a');

  if (!sections.length || !navLinks.length) return;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const id = entry.target.id;
        navLinks.forEach(link => {
          const href = link.getAttribute('href');
          link.classList.toggle('active', href === `#${id}`);
          if (href === `#${id}`) {
            link.style.color = 'var(--green-bright)';
          } else {
            link.style.color = '';
          }
        });
      }
    });
  }, { threshold: 0.45 });

  sections.forEach(s => observer.observe(s));
}

/* ─────────────────────────────────────────────────────────────
   8. HERO COUNTER ANIMATION
───────────────────────────────────────────────────────────── */
function animateCounters() {
  const counters = $$('[data-count]');
  if (!counters.length) return;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el     = entry.target;
      const target = parseInt(el.dataset.count, 10);
      observer.unobserve(el);

      // Skip animation for non-numeric values (e.g. 'K1,000', '100K+')
      if (isNaN(target)) return;

      const suffix = el.dataset.suffix ?? '';
      const duration = 1200;
      const start  = performance.now();

      const tick = (now) => {
        const progress = Math.min((now - start) / duration, 1);
        const ease = 1 - Math.pow(1 - progress, 3); // easeOutCubic
        el.textContent = Math.floor(ease * target) + suffix;
        if (progress < 1) requestAnimationFrame(tick);
      };

      requestAnimationFrame(tick);
    });
  }, { threshold: 0.5 });

  counters.forEach(el => observer.observe(el));
}

/* ─────────────────────────────────────────────────────────────
   9. FLAG STRIPE BUILDER (inject dynamically if needed)
───────────────────────────────────────────────────────────── */
function buildFlagStripes(root = document) {
  $$('.flag-stripe', root).forEach(stripe => {
    if (stripe.children.length) return; // already has children
    ['', '', '', ''].forEach(() => {
      const span = document.createElement('span');
      stripe.appendChild(span);
    });
  });
}

/* ─────────────────────────────────────────────────────────────
   10. KEYBOARD ACCESSIBILITY
───────────────────────────────────────────────────────────── */
function initA11y() {
  // Close mobile menu on Escape — use shared setMobileNavOpen to avoid state desync
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && LFS.state.navOpen) {
      setMobileNavOpen(false);
      $('.lfs-nav__hamburger')?.focus();
    }
  });

  // Focus-visible polyfill — add keyboard nav class
  document.addEventListener('keydown', () => {
    document.body.classList.add('keyboard-nav');
  });
  document.addEventListener('mousedown', () => {
    document.body.classList.remove('keyboard-nav');
  });
}

/* ─────────────────────────────────────────────────────────────
   11. SATELLITE CARD RIPPLE (micro-interaction)
───────────────────────────────────────────────────────────── */
function initRipple(root = document) {
  $$('.satellite-card, .btn, .product-card__add', root).forEach(el => {
    el.addEventListener('click', function (e) {
      const rect   = this.getBoundingClientRect();
      const ripple = document.createElement('span');
      const size   = Math.max(rect.width, rect.height);
      const x = e.clientX - rect.left - size / 2;
      const y = e.clientY - rect.top  - size / 2;

      ripple.style.cssText = `
        position: absolute;
        width: ${size}px;
        height: ${size}px;
        top: ${y}px;
        left: ${x}px;
        background: rgba(255,255,255,0.18);
        border-radius: 50%;
        pointer-events: none;
        transform: scale(0);
        animation: ripple-anim 0.5s ease-out forwards;
      `;

      // Inject keyframe if not present
      if (!document.getElementById('ripple-style')) {
        const style = document.createElement('style');
        style.id = 'ripple-style';
        style.textContent = `
          @keyframes ripple-anim {
            to { transform: scale(2.5); opacity: 0; }
          }
        `;
        document.head.appendChild(style);
      }

      // Card must be relative for ripple to work
      const pos = getComputedStyle(this).position;
      if (pos === 'static') this.style.position = 'relative';
      this.style.overflow = 'hidden';
      this.appendChild(ripple);
      ripple.addEventListener('animationend', () => ripple.remove());
    });
  });
}

/* ─────────────────────────────────────────────────────────────
   12. PUBLIC API (for inline HTML onclick compatibility)
───────────────────────────────────────────────────────────── */
window.addToCart    = (btn)       => addToCart(btn);
window.viewCart     = ()          => viewCart();
window.handleSubmit = (btn, form) => contactHandleSubmit(btn, form);
window.showToast    = showToast;

/* ─────────────────────────────────────────────────────────────
   13. BOOT
───────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initNav();
  initScrollReveal();
  initCart();
  initContactForm();
  initActiveLinks();
  animateCounters();
  buildFlagStripes();
  initA11y();

  // Ripple slight delay so DOM is painted
  requestAnimationFrame(initRipple);

});

/* ─────────────────────────────────────────────────────────────
   14. ACCOUNT SOFT-NAV CONTENT SWAP
   Fired by account-nav.js after it replaces #main-content — only
   re-run the behaviors scoped to that container, so listeners on
   the navbar/cart FAB (outside #main-content) never get doubled.
───────────────────────────────────────────────────────────── */
window.addEventListener('lfs:account-nav', (e) => {
  const root = e.detail?.container ?? document;
  initScrollReveal(root);
  buildFlagStripes(root);
  requestAnimationFrame(() => initRipple(root));
});
