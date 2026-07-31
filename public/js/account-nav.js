/* ============================================================
   LFS — Account section soft navigation
   Intercepts clicks on /account links and fetches the next page
   instead of a full reload, keeping back/forward working through
   pushState/popstate.

   Only #account-tab-panel (tabs nav + status alerts + tab body)
   is swapped — the profile card above it is identical on every
   account page, so leaving it untouched avoids the re-render
   flash a full #main-content swap would cause.
   ============================================================ */

'use strict';

(() => {
  const CONTENT_SELECTOR = '#main-content';
  const PANEL_SELECTOR = '#account-tab-panel';
  const ACCOUNT_PREFIX = '/account';

  let currentController = null;

  function isAccountUrl(url) {
    return url.origin === window.location.origin
      && (url.pathname === ACCOUNT_PREFIX || url.pathname.startsWith(ACCOUNT_PREFIX + '/'));
  }

  function isEligibleLink(link) {
    if (!link || link.target || link.hasAttribute('download')) return false;
    if (link.hasAttribute('data-full-reload')) return false;

    let url;
    try {
      url = new URL(link.href, window.location.href);
    } catch {
      return false;
    }

    return isEligibleUrl(url);
  }

  function isEligibleUrl(url) {
    return isAccountUrl(url) && url.href !== window.location.href;
  }

  async function swapTo(url, { push }) {
    const main = document.querySelector(CONTENT_SELECTOR);
    if (!main) {
      window.location.href = url;
      return;
    }

    const livePanel = main.querySelector(PANEL_SELECTOR);
    const loadingTarget = livePanel || main;

    currentController?.abort();
    const controller = new AbortController();
    currentController = controller;

    loadingTarget.classList.add('is-loading');

    try {
      const res = await fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      if (!res.ok) throw new Error(`Request failed: ${res.status}`);

      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const newPanel = livePanel ? doc.querySelector(PANEL_SELECTOR) : null;
      const swapTarget = newPanel ? livePanel : main;
      const newSource = newPanel || doc.querySelector(CONTENT_SELECTOR);
      if (!newSource) throw new Error('No matching content in response');

      swapTarget.innerHTML = newSource.innerHTML;
      document.title = doc.title;

      if (push) {
        window.history.pushState({}, '', url);
      }

      window.scrollTo(0, 0);
      window.dispatchEvent(new CustomEvent('lfs:account-nav', { detail: { container: swapTarget } }));
    } catch (err) {
      if (err.name === 'AbortError') return;
      window.location.href = url;
    } finally {
      loadingTarget.classList.remove('is-loading');
    }
  }

  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    const link = e.target.closest('a[href]');
    if (!isEligibleLink(link)) return;

    e.preventDefault();
    swapTo(link.href, { push: true });
  });

  window.addEventListener('popstate', () => {
    if (!isAccountUrl(new URL(window.location.href))) return;
    swapTo(window.location.href, { push: false });
  });
})();

(() => {
  let previousTitle = '';

  function preparePrintTitle() {
    previousTitle = document.title;
    document.title = ' ';
  }

  function restorePrintTitle() {
    if (previousTitle) {
      document.title = previousTitle;
      previousTitle = '';
    }
  }

  document.addEventListener('click', (e) => {
    const printBtn = e.target.closest('[data-print-card]');
    if (!printBtn) return;
    e.preventDefault();
    preparePrintTitle();
    window.print();
  });

  window.addEventListener('beforeprint', preparePrintTitle);
  window.addEventListener('afterprint', restorePrintTitle);
})();

(() => {
  function lightbox() {
    return document.getElementById('membership-card-lightbox');
  }

  function openCard() {
    const el = lightbox();
    if (!el) return;
    el.classList.remove('is-hidden');
    el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('membership-card-lightbox-open');
    el.querySelector('[data-close-membership-card].membership-card-lightbox__close')?.focus();
  }

  function closeCard() {
    const el = lightbox();
    if (!el || el.classList.contains('is-hidden')) return;
    el.classList.add('is-hidden');
    el.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('membership-card-lightbox-open');
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-open-membership-card]')) {
      e.preventDefault();
      openCard();
      return;
    }

    if (e.target.closest('[data-close-membership-card]')) {
      e.preventDefault();
      closeCard();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeCard();
  });

  window.addEventListener('lfs:account-nav', () => {
    document.body.classList.remove('membership-card-lightbox-open');
  });
})();
