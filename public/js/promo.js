/* ============================================================
   LFS — promo.js
   Apply / remove a shop promo code on the cart and checkout pages.
   The server prices everything; on success we just reload so the
   page shows exactly what the server will charge.
   ============================================================ */

'use strict';

(function () {
  function csrf() {
    const m = document.cookie.match(/(?:^|;\s*)lfs_csrf=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }

  async function post(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrf(),
      },
      body: JSON.stringify(body || {}),
    });
    let data = null;
    try { data = await res.json(); } catch (_) {}
    return { ok: res.ok && data && data.ok, data };
  }

  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-promo-form]');
    if (!form) return;
    e.preventDefault();

    const msg = form.parentElement.querySelector('[data-promo-msg]');
    const btn = form.querySelector('[data-promo-apply]');
    const code = (form.querySelector('input[name="code"]').value || '').trim();
    if (!code) return;

    if (btn) btn.disabled = true;
    if (msg) msg.hidden = true;

    const { ok, data } = await post('/shop/cart/promo', { code });
    if (ok) {
      window.location.reload();
      return;
    }

    if (btn) btn.disabled = false;
    if (msg) {
      msg.textContent = (data && (data.message || (data.errors && Object.values(data.errors)[0][0])))
        || 'Could not apply that code. Please try again.';
      msg.hidden = false;
    }
  });

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-promo-remove]');
    if (!btn) return;
    btn.disabled = true;
    await post('/shop/cart/promo/remove');
    window.location.reload();
  });
})();
