/* ============================================================
   LFS — Lusaka Fitness Squad
   js/cookie-banner.js (web root)
   
   Handles:
     • Showing the cookie consent banner if no decision exists
     • Accept All / Necessary Only quick actions
     • Manage Preferences panel (granular toggles)
     • Submitting consent to POST /cookies/consent
     • Remembering decision so banner doesn't reappear
   
   No dependencies — vanilla JS only.
   ============================================================ */

(function () {
  'use strict';

  /* ── Config ──────────────────────────────────────────────── */
  const CONSENT_COOKIE = 'lfs_consent';
  const CONSENT_ENDPOINT = '/cookies/consent';
  const WITHDRAW_ENDPOINT = '/cookies/withdraw';

  /* ════════════════════════════════════════════════════════════
     COOKIE HELPERS
     ════════════════════════════════════════════════════════════ */
  function getCookie(name) {
    const match = document.cookie
      .split('; ')
      .find((row) => row.startsWith(name + '='));
    if (!match) return null;
    try {
      return JSON.parse(decodeURIComponent(match.split('=').slice(1).join('=')));
    } catch {
      return null;
    }
  }

  function hasConsent() {
    return getCookie(CONSENT_COOKIE) !== null;
  }

  /* ════════════════════════════════════════════════════════════
     POST CONSENT
     ════════════════════════════════════════════════════════════ */
  async function postConsent(payload) {
    try {
      const body = new URLSearchParams(payload).toString();
      const res = await fetch(CONSENT_ENDPOINT, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      });
      return res.ok;
    } catch (err) {
      console.error('[LFS Cookie] Failed to save consent:', err);
      return false;
    }
  }

  /* ════════════════════════════════════════════════════════════
     BANNER DOM BUILDER
     Injects the banner HTML if it doesn't already exist in the DOM.
     If you include the PHP partial, this function is skipped.
     ════════════════════════════════════════════════════════════ */
  function buildBanner() {
    if (document.getElementById('lfs-cookie-banner')) return; // already rendered by PHP partial

    const banner = document.createElement('div');
    banner.id = 'lfs-cookie-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-modal', 'true');
    banner.setAttribute('aria-label', 'Cookie consent');
    banner.innerHTML = `
      <div class="lfs-cookie-inner">
        <div class="lfs-cookie-flag-bar"></div>

        <div class="lfs-cookie-body">
          <div class="lfs-cookie-text">
            <p class="lfs-cookie-title">We use cookies</p>
            <p class="lfs-cookie-desc">
              LFS uses cookies to keep the site running and to understand how
              you engage with our community. You're in control.
              <a href="/privacy" class="lfs-cookie-link">Privacy Policy</a>
            </p>
          </div>

          <div class="lfs-cookie-actions">
            <button id="lfs-cookie-accept-all"  class="lfs-btn lfs-btn-green">Accept All</button>
            <button id="lfs-cookie-necessary"    class="lfs-btn lfs-btn-outline">Necessary Only</button>
            <button id="lfs-cookie-manage"       class="lfs-btn lfs-btn-ghost">Manage</button>
          </div>
        </div>

        <!-- Granular preferences panel (hidden by default) -->
        <div id="lfs-cookie-prefs" class="lfs-cookie-prefs" hidden>
          <p class="lfs-prefs-title">Cookie Preferences</p>
          <ul class="lfs-prefs-list">
            <li class="lfs-pref-item">
              <span>
                <strong>Necessary</strong>
                <small>Required for the site to function.</small>
              </span>
              <input type="checkbox" id="pref-necessary" checked disabled aria-label="Necessary cookies (always on)">
            </li>
            <li class="lfs-pref-item">
              <span>
                <strong>Analytics</strong>
                <small>Help us understand how visitors use the site.</small>
              </span>
              <input type="checkbox" id="pref-analytics" aria-label="Analytics cookies">
            </li>
            <li class="lfs-pref-item">
              <span>
                <strong>Preferences</strong>
                <small>Remember your settings (theme, language).</small>
              </span>
              <input type="checkbox" id="pref-preferences" aria-label="Preference cookies">
            </li>
            <li class="lfs-pref-item">
              <span>
                <strong>Marketing</strong>
                <small>Personalised content &amp; ads.</small>
              </span>
              <input type="checkbox" id="pref-marketing" aria-label="Marketing cookies">
            </li>
          </ul>
          <div class="lfs-prefs-actions">
            <button id="lfs-cookie-save-prefs" class="lfs-btn lfs-btn-green">Save Preferences</button>
            <button id="lfs-cookie-back"        class="lfs-btn lfs-btn-ghost">← Back</button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(banner);
  }

  /* ════════════════════════════════════════════════════════════
     INJECT STYLES
     Minimal inline styles so the banner works even if main.css
     hasn't loaded yet. Uses LFS brand tokens as fallback values.
     ════════════════════════════════════════════════════════════ */
  function injectStyles() {
    if (document.getElementById('lfs-cookie-styles')) return;

    const style = document.createElement('style');
    style.id = 'lfs-cookie-styles';
    style.textContent = `
      #lfs-cookie-banner {
        position: fixed;
        bottom: 1.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: min(92vw, 720px);
        max-width: 100%;
        box-sizing: border-box;
        background: #141414;
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 0.5rem;
        box-shadow: 0 8px 40px rgba(0,0,0,0.5);
        z-index: 9999;
        font-family: 'DM Sans', sans-serif;
        color: #f5f2ec;
        max-height: calc(100vh - 2rem);
        max-height: min(calc(100vh - 2rem), calc(100dvh - 2rem));
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        animation: lfs-slide-up 0.4s ease forwards;
      }
      #lfs-cookie-banner *, #lfs-cookie-banner *::before, #lfs-cookie-banner *::after { box-sizing: border-box; }
      @keyframes lfs-slide-up {
        from { opacity: 0; transform: translateX(-50%) translateY(20px); }
        to   { opacity: 1; transform: translateX(-50%) translateY(0); }
      }
      @keyframes lfs-slide-up-mobile {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
      }
      .lfs-cookie-flag-bar {
        height: 3px;
        background: #e07b39;
      }
      .lfs-cookie-body {
        display: flex;
        align-items: flex-start;
        gap: 1.25rem;
        padding: 1.25rem clamp(1rem, 4vw, 1.5rem);
        flex-wrap: wrap;
      }
      .lfs-cookie-text { flex: 1 1 220px; min-width: 0; }
      .lfs-cookie-title {
        font-size: 1rem;
        font-weight: 700;
        margin: 0 0 0.35rem;
        letter-spacing: 0.02em;
      }
      .lfs-cookie-desc {
        font-size: 0.82rem;
        color: rgba(245,242,236,0.72);
        margin: 0;
        line-height: 1.5;
      }
      .lfs-cookie-link {
        color: #7ecb93;
        text-decoration: underline;
      }
      .lfs-cookie-actions {
        display: flex;
        gap: 0.6rem;
        flex: 1 1 200px;
        min-width: 0;
        flex-wrap: wrap;
        align-items: stretch;
        justify-content: flex-end;
      }
      .lfs-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.55rem 1.1rem;
        border-radius: 0.25rem;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: background 0.2s, color 0.2s;
        white-space: nowrap;
        flex: 0 1 auto;
        min-width: 0;
      }
      .lfs-btn-green   { background: #4a7c59; color: #fff; }
      .lfs-btn-green:hover { background: #6aad7e; }
      .lfs-btn-outline { background: transparent; color: #f5f2ec; border: 1px solid rgba(255,255,255,0.25); }
      .lfs-btn-outline:hover { border-color: #e07b39; color: #e07b39; }
      .lfs-btn-ghost   { background: transparent; color: rgba(245,242,236,0.55); font-weight: 400; }
      .lfs-btn-ghost:hover { color: #e07b39; }

      .lfs-cookie-prefs { padding: 1.25rem clamp(1rem, 4vw, 1.5rem) 1.5rem; border-top: 1px solid rgba(255,255,255,0.08); }
      .lfs-prefs-title  { font-size: 0.85rem; font-weight: 700; margin: 0 0 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: #7ecb93; }
      .lfs-prefs-list   { list-style: none; padding: 0; margin: 0 0 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
      .lfs-pref-item    { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; column-gap: 1rem; row-gap: 0.35rem; padding: 0.6rem 0.75rem; background: rgba(255,255,255,0.04); border-radius: 0.25rem; }
      .lfs-pref-item span { min-width: 0; }
      .lfs-pref-item span strong { display: block; font-size: 0.85rem; }
      .lfs-pref-item span small  { display: block; font-size: 0.75rem; color: rgba(245,242,236,0.55); }
      .lfs-pref-item input[type="checkbox"] { width: 1.1rem; height: 1.1rem; flex-shrink: 0; cursor: pointer; accent-color: #4a7c59; }
      .lfs-pref-item input:disabled { cursor: not-allowed; opacity: 0.5; }
      .lfs-prefs-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; }

      @media (max-width: 768px) {
        .lfs-cookie-body { flex-direction: column; align-items: stretch; }
        .lfs-cookie-actions { flex: 1 1 auto; width: 100%; justify-content: stretch; }
        .lfs-btn { white-space: normal; text-align: center; flex: 1 1 calc(50% - 0.35rem); min-width: min(100%, 8.5rem); }
        .lfs-cookie-actions .lfs-btn-ghost { flex: 1 1 100%; }
      }
      @media (max-width: 560px) {
        #lfs-cookie-banner {
          bottom: 0; left: 0; right: 0; transform: none; width: 100%; max-width: none;
          border-radius: 0.75rem 0.75rem 0 0; padding-bottom: env(safe-area-inset-bottom, 0px);
          max-height: 90vh;
          max-height: min(90vh, 90dvh); animation-name: lfs-slide-up-mobile;
        }
        .lfs-cookie-actions { flex-direction: column; }
        .lfs-btn { flex: none; width: 100%; min-width: 0; }
        .lfs-prefs-actions { flex-direction: column; }
        .lfs-prefs-actions .lfs-btn { width: 100%; justify-content: center; }
      }
    `;
    document.head.appendChild(style);
  }

  /* ════════════════════════════════════════════════════════════
     HIDE BANNER (animate out)
     Returns a cancel function — call it within 350 ms to abort
     the removal and restore the banner (used on request failure).
     ════════════════════════════════════════════════════════════ */
  function hideBanner() {
    const banner = document.getElementById('lfs-cookie-banner');
    if (!banner) return () => {};
    const isMobile = window.innerWidth <= 560;
    banner.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
    banner.style.opacity    = '0';
    banner.style.transform  = isMobile ? 'translateY(20px)' : 'translateX(-50%) translateY(20px)';
    const timer = setTimeout(() => banner.remove(), 350);
    return function cancelHide() {
      clearTimeout(timer);
      banner.style.opacity   = '';
      banner.style.transform = '';
    };
  }

  /* ════════════════════════════════════════════════════════════
     BIND EVENTS
     ════════════════════════════════════════════════════════════ */
  function bindEvents() {
    const banner = document.getElementById('lfs-cookie-banner');
    if (!banner) return;

    const prefsPanel = document.getElementById('lfs-cookie-prefs');
    const mainBody   = banner.querySelector('.lfs-cookie-body');

    /* Accept All ─────────────────────────────────────────── */
    banner.querySelector('#lfs-cookie-accept-all')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const btn      = e.currentTarget;
      btn.disabled   = true;
      const cancelHide = hideBanner();
      const ok       = await postConsent({ accept: 'all' });
      if (ok) {
        dispatchConsentEvent({ necessary: true, analytics: true, preferences: true, marketing: true });
      } else {
        cancelHide();
        btn.disabled = false;
      }
    });

    /* Necessary Only ─────────────────────────────────────── */
    banner.querySelector('#lfs-cookie-necessary')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const btn        = e.currentTarget;
      btn.disabled     = true;
      const cancelHide = hideBanner();
      const ok         = await postConsent({ accept: 'necessary' });
      if (ok) {
        dispatchConsentEvent({ necessary: true, analytics: false, preferences: false, marketing: false });
      } else {
        cancelHide();
        btn.disabled = false;
      }
    });

    /* Manage → show prefs panel ──────────────────────────── */
    banner.querySelector('#lfs-cookie-manage')?.addEventListener('click', (e) => {
      e.preventDefault();
      if (mainBody)   mainBody.hidden   = true;
      if (prefsPanel) prefsPanel.hidden = false;
    });

    /* Back → hide prefs panel ────────────────────────────── */
    banner.querySelector('#lfs-cookie-back')?.addEventListener('click', (e) => {
      e.preventDefault();
      if (mainBody)   mainBody.hidden   = false;
      if (prefsPanel) prefsPanel.hidden = true;
    });

    /* Save Preferences ───────────────────────────────────── */
    banner.querySelector('#lfs-cookie-save-prefs')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const btn         = e.currentTarget;
      btn.disabled      = true;
      const analytics   = document.getElementById('pref-analytics')?.checked   || false;
      const preferences = document.getElementById('pref-preferences')?.checked || false;
      const marketing   = document.getElementById('pref-marketing')?.checked   || false;

      const cancelHide = hideBanner();
      const ok         = await postConsent({
        necessary:   true,
        analytics:   String(analytics),
        preferences: String(preferences),
        marketing:   String(marketing),
      });

      if (ok) {
        dispatchConsentEvent({ necessary: true, analytics, preferences, marketing });
      } else {
        cancelHide();
        btn.disabled = false;
      }
    });

    /* Prevent form submit when JS handles consent (PHP-rendered forms) */
    banner.querySelectorAll('form[action*="/cookies/consent"]').forEach((form) => {
      form.addEventListener('submit', (e) => { e.preventDefault(); });
    });
  }

  /* ════════════════════════════════════════════════════════════
     CUSTOM EVENT
     Fired after consent is saved so other scripts can react
     (e.g. load GA only after analytics consent).
     ════════════════════════════════════════════════════════════ */
  function dispatchConsentEvent(consent) {
    window.dispatchEvent(new CustomEvent('lfs:consent', { detail: consent }));
  }

  /* ════════════════════════════════════════════════════════════
     WITHDRAW CONSENT HELPER
     Call this from your privacy settings page:
       window.LFSCookies.withdraw();
     ════════════════════════════════════════════════════════════ */
  async function withdraw() {
    try {
      const res = await fetch(WITHDRAW_ENDPOINT, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
      });
      if (res.ok) {
        window.location.reload();
      } else {
        console.error('[LFS Cookie] Withdraw failed with status:', res.status);
      }
    } catch (err) {
      console.error('[LFS Cookie] Withdraw failed:', err);
    }
  }

  /* ════════════════════════════════════════════════════════════
     PUBLIC API
     ════════════════════════════════════════════════════════════ */
  window.LFSCookies = {
    getConsent: () => getCookie(CONSENT_COOKIE),
    withdraw,
    hasConsent,
  };

  /* ════════════════════════════════════════════════════════════
     INIT
     Only build/inject when banner is not already server-rendered (PHP partial).
     ════════════════════════════════════════════════════════════ */
  function init() {
    if (hasConsent()) return; // banner already dismissed

    const existingBanner = document.getElementById('lfs-cookie-banner');
    if (!existingBanner) {
      injectStyles();
      buildBanner();
    }
    bindEvents();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
