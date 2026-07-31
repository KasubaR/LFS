/* ============================================================
   LFS — blog.js
   Blog-specific JS: copy-link, search clear, scroll spy,
   lazy image reveal, video embeds, reading progress bar.
   Assumes main.js globals: $(), $$() helper shortcuts.
   ============================================================ */

'use strict';

/* ─────────────────────────────────────────────────────────────
   HELPERS (use main.js $/$$ if present, else local q/qAll)
───────────────────────────────────────────────────────────── */
const q = typeof window.$ !== 'undefined' ? window.$ : (sel, ctx = document) => (ctx.querySelector(sel));
const qAll = typeof window.$$ !== 'undefined' ? window.$$ : (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

/* ─────────────────────────────────────────────────────────────
   READING PROGRESS BAR (single post page)
───────────────────────────────────────────────────────────── */
function initReadingProgress() {
  const content = document.getElementById('post-content');
  if (!content) return;

  /* Inject bar */
  const bar = document.createElement('div');
  bar.className = 'blog-progress-bar';
  bar.setAttribute('aria-hidden', 'true');
  bar.setAttribute('role', 'progressbar');
  bar.setAttribute('aria-valuemin', '0');
  bar.setAttribute('aria-valuemax', '100');
  document.body.appendChild(bar);

  /* Inject styles inline so blog.css doesn't need to know about body */
  const style = document.createElement('style');
  style.textContent = `
    .blog-progress-bar {
      position: fixed;
      top: 0;
      left: 0;
      height: 3px;
      width: 0%;
      background: linear-gradient(to right, #198a4e, #7ecb93, #e07b39);
      z-index: 9999;
      transition: width 0.1s linear;
      pointer-events: none;
    }
  `;
  document.head.appendChild(style);

  const update = () => {
    const rect   = content.getBoundingClientRect();
    const total  = content.offsetHeight;
    const start  = content.offsetTop;
    const scroll = window.scrollY - start;
    const pct    = Math.min(100, Math.max(0, (scroll / (total - window.innerHeight)) * 100));
    bar.style.width = pct + '%';
    bar.setAttribute('aria-valuenow', Math.round(pct));
  };

  window.addEventListener('scroll', update, { passive: true });
  update();
}

/* ─────────────────────────────────────────────────────────────
   COPY LINK BUTTONS (all [data-copy-link] on page)
───────────────────────────────────────────────────────────── */
function initCopyLink() {
  qAll('[data-copy-link]').forEach(btn => {
    if (!navigator.clipboard) return;
    btn.addEventListener('click', () => {
      navigator.clipboard.writeText(window.location.href).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.disabled = true;
        setTimeout(() => {
          btn.innerHTML = orig;
          btn.disabled = false;
        }, 2200);
      });
    });
  });
}

/* ─────────────────────────────────────────────────────────────
   LAZY IMAGE REVEAL (Intersection Observer fade-in)
───────────────────────────────────────────────────────────── */
function initLazyReveal() {
  if (!window.IntersectionObserver) return;

  /* Blog cards */
  const cards = qAll('.blog-card, .blog-featured, .related-posts__grid .blog-card');
  if (!cards.length) return;

  /* Reset animation so it re-fires on scroll */
  cards.forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity 0.55s ease, transform 0.55s ease';
  });

  const obs = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.style.opacity = '1';
        entry.target.style.transform = 'translateY(0)';
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

  cards.forEach((el, i) => {
    /* Stagger via transition-delay */
    el.style.transitionDelay = (i % 3) * 0.08 + 's';
    obs.observe(el);
  });
}

/* ─────────────────────────────────────────────────────────────
   RESPONSIVE YOUTUBE / VIDEO EMBEDS
   Wraps <iframe> inside .video-embed for 16:9 ratio
───────────────────────────────────────────────────────────── */
function initVideoEmbeds() {
  const content = document.querySelector('.post-content');
  if (!content) return;

  content.querySelectorAll('iframe[src*="youtube"], iframe[src*="youtu.be"], iframe[src*="vimeo"]')
    .forEach(iframe => {
      if (!iframe.parentElement.classList.contains('video-embed')) {
        const wrap = document.createElement('div');
        wrap.className = 'video-embed';
        iframe.parentNode.insertBefore(wrap, iframe);
        wrap.appendChild(iframe);
        iframe.style.width  = '100%';
        iframe.style.height = '100%';
      }
    });
}

/* ─────────────────────────────────────────────────────────────
   SEARCH INPUT — clear button
───────────────────────────────────────────────────────────── */
function initSearchClear() {
  const input = document.querySelector('.blog-search__input');
  if (!input) return;

  const wrap = input.closest('.blog-search__wrap');
  if (!wrap) return;

  const clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.className = 'blog-search__clear';
  clearBtn.setAttribute('aria-label', 'Clear search');
  clearBtn.innerHTML = '<i class="fas fa-times"></i>';

  /* Inject styles */
  const style = document.createElement('style');
  style.textContent = `
    .blog-search__clear {
      background: none;
      border: none;
      color: rgba(255,255,255,0.4);
      padding: 0 0.75rem;
      cursor: pointer;
      font-size: 0.8rem;
      display: none;
      transition: color 150ms ease;
    }
    .blog-search__clear:hover { color: rgba(255,255,255,0.75); }
    .blog-search__clear.visible { display: block; }
  `;
  document.head.appendChild(style);

  /* Insert before submit btn */
  const submitBtn = wrap.querySelector('.blog-search__btn');
  wrap.insertBefore(clearBtn, submitBtn);

  const toggle = () => {
    clearBtn.classList.toggle('visible', input.value.length > 0);
  };

  input.addEventListener('input', toggle);
  toggle();

  clearBtn.addEventListener('click', () => {
    input.value = '';
    input.focus();
    clearBtn.classList.remove('visible');
    /* If we want live clear — submit the form */
    const form = input.closest('form');
    if (form) form.submit();
  });
}

/* ─────────────────────────────────────────────────────────────
   FILTER CHIP — active indicator accessibility
───────────────────────────────────────────────────────────── */
function initFilterChips() {
  qAll('.blog-filter-chip').forEach(chip => {
    chip.setAttribute('role', 'link');
  });
}

/* ─────────────────────────────────────────────────────────────
   SMOOTH ANCHOR SCROLL (ToC links inside posts, if any)
───────────────────────────────────────────────────────────── */
function initSmoothAnchor() {
  qAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', (e) => {
      const id  = link.getAttribute('href').slice(1);
      const el  = document.getElementById(id);
      if (!el) return;
      e.preventDefault();
      const offset = 80; /* height of fixed nav */
      const top = el.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top, behavior: 'smooth' });
    });
  });
}

/* ─────────────────────────────────────────────────────────────
   BACK TO TOP (inject on post pages)
───────────────────────────────────────────────────────────── */
function initBackToTop() {
  if (!document.querySelector('.post-hero')) return; /* only on post pages */

  const btn = document.createElement('button');
  btn.className = 'blog-btt';
  btn.setAttribute('aria-label', 'Back to top');
  btn.innerHTML = '<i class="fas fa-arrow-up"></i>';

  const style = document.createElement('style');
  style.textContent = `
    .blog-btt {
      position: fixed;
      bottom: 1.5rem;
      right: 1.5rem;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: #198a4e;
      color: #fff;
      border: none;
      cursor: pointer;
      font-size: 0.85rem;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      transform: translateY(8px);
      transition: opacity 0.3s ease, transform 0.3s ease, background 0.2s ease;
      z-index: 200;
      box-shadow: 0 4px 16px rgba(0,0,0,0.2);
    }
    .blog-btt.visible { opacity: 1; transform: translateY(0); }
    .blog-btt:hover { background: #e07b39; }
  `;
  document.head.appendChild(style);
  document.body.appendChild(btn);

  window.addEventListener('scroll', () => {
    btn.classList.toggle('visible', window.scrollY > 600);
  }, { passive: true });

  btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
}

/* ─────────────────────────────────────────────────────────────
   BOOT
───────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initReadingProgress();
  initCopyLink();
  initLazyReveal();
  initVideoEmbeds();
  initSearchClear();
  initFilterChips();
  initSmoothAnchor();
  initBackToTop();
});
