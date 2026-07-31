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

  function scrollActiveAccountTabIntoView(root) {
    const scope = root && root.querySelector ? root : document;
    const list = scope.querySelector('.account-tabs__list');
    const active = scope.querySelector('.account-tabs__link.is-active, .account-tabs__link[aria-current="page"]');
    if (!list || !active) return;

    // Soft-nav re-renders the chips and resets scrollLeft to 0; keep
    // the selected chip in view on narrow screens.
    const listRect = list.getBoundingClientRect();
    const activeRect = active.getBoundingClientRect();
    const nextLeft = list.scrollLeft
      + (activeRect.left - listRect.left)
      - (listRect.width - activeRect.width) / 2;

    list.scrollTo({
      left: Math.max(0, nextLeft),
      behavior: 'auto',
    });
  }

  function markTabPending(link) {
    const list = link.closest('.account-tabs__list');
    if (!list) return;

    list.querySelectorAll('.account-tabs__link').forEach((tab) => {
      tab.classList.remove('is-active', 'is-pending');
      tab.removeAttribute('aria-current');
    });

    link.classList.add('is-active', 'is-pending');
    link.setAttribute('aria-current', 'page');
    scrollActiveAccountTabIntoView(document);
  }

  function showLoading(target) {
    target.classList.add('is-loading');
    target.setAttribute('aria-busy', 'true');

    if (target.querySelector('.account-nav-loading')) return;

    const el = document.createElement('div');
    el.className = 'account-nav-loading';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = ''
      + '<div class="account-nav-loading__bar" aria-hidden="true"></div>'
      + '<div class="account-nav-loading__body">'
      +   '<span class="account-nav-loading__spinner" aria-hidden="true"></span>'
      +   '<span class="account-nav-loading__text">Loading…</span>'
      + '</div>';
    target.appendChild(el);
  }

  function hideLoading(target) {
    target.classList.remove('is-loading');
    target.removeAttribute('aria-busy');
    target.querySelector('.account-nav-loading')?.remove();
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

    showLoading(loadingTarget);

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

      swapTarget.scrollIntoView({ block: 'start' });
      scrollActiveAccountTabIntoView(swapTarget);
      window.dispatchEvent(new CustomEvent('lfs:account-nav', { detail: { container: swapTarget } }));
    } catch (err) {
      if (err.name === 'AbortError') return;
      window.location.href = url;
    } finally {
      // Only clear loading for the latest navigation — an aborted
      // request must not remove the spinner for a newer one.
      if (currentController === controller) {
        hideLoading(loadingTarget);
      }
    }
  }

  // Submits a form via fetch and swaps #account-tab-panel with the response,
  // same as swapTo() above but for POST forms that redirect back into the
  // account area (e.g. changing plan). Falls back to a real submit on any
  // failure so the action always completes, just without the soft-swap.
  async function submitFormSwap(form) {
    const main = document.querySelector(CONTENT_SELECTOR);
    const livePanel = main?.querySelector(PANEL_SELECTOR);
    if (!main || !livePanel) {
      form.submit();
      return;
    }

    showLoading(livePanel);

    try {
      const res = await fetch(form.action, {
        method: form.method || 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      });
      if (!res.ok) throw new Error(`Request failed: ${res.status}`);

      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const newPanel = doc.querySelector(PANEL_SELECTOR);
      if (!newPanel) throw new Error('No matching content in response');

      livePanel.innerHTML = newPanel.innerHTML;
      document.title = doc.title;

      // The profile card above the panel is intentionally left untouched by
      // every swap (see file header) — but a plan change actually changes
      // its "Plan" field, so patch that one value in directly.
      const newPlanName = doc.querySelector('[data-account-plan-name]');
      const livePlanName = document.querySelector('[data-account-plan-name]');
      if (newPlanName && livePlanName) {
        livePlanName.textContent = newPlanName.textContent;
      }

      scrollActiveAccountTabIntoView(livePanel);
      window.dispatchEvent(new CustomEvent('lfs:account-nav', { detail: { container: livePanel } }));
    } catch {
      form.submit();
    } finally {
      hideLoading(livePanel);
    }
  }

  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    const link = e.target.closest('a[href]');
    if (!isEligibleLink(link)) return;

    e.preventDefault();
    if (link.closest('.account-tabs')) {
      markTabPending(link);
    }
    swapTo(link.href, { push: true });
  });

  document.addEventListener('change', (e) => {
    const radio = e.target.closest('form[data-auto-submit-on-select] input');
    if (!radio) return;

    submitFormSwap(radio.form);
  });

  window.addEventListener('popstate', () => {
    if (!isAccountUrl(new URL(window.location.href))) return;
    swapTo(window.location.href, { push: false });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => scrollActiveAccountTabIntoView(document));
  } else {
    scrollActiveAccountTabIntoView(document);
  }
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
