/**
 * LFS — ticket order page (pages/ticket-order.blade.php)
 *
 * While payment is pending (or a lapsed order could still be settled by a late
 * payment), asks the server where it stands and reloads when it changes.
 * The server is the only judge; this script never decides an outcome itself.
 */
'use strict';

(function () {
  const card = document.getElementById('order-card');
  if (!card) return;

  const statusUrl = card.dataset.statusUrl;
  const watching = card.dataset.watching === '1';
  const startStatus = card.dataset.status;
  const expiresAt = card.dataset.expiresAt ? new Date(card.dataset.expiresAt) : null;
  const countdown = document.getElementById('countdown');
  const msg = document.getElementById('poll-msg');

  const POLL_MS = 6000;
  const MAX_MINUTES = 20;
  const startedAt = Date.now();
  let timer = null;

  function tick() {
    if (!countdown || !expiresAt) return;
    const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
    const m = String(Math.floor(left / 60)).padStart(2, '0');
    const s = String(left % 60).padStart(2, '0');
    countdown.textContent = m + ':' + s;
    if (left === 0) countdown.textContent = 'a moment';
  }

  async function poll() {
    if (Date.now() - startedAt > MAX_MINUTES * 60000) {
      clearInterval(timer);
      if (msg) msg.textContent = 'This is taking a while. If you approved the payment you do not need to pay again; check your email shortly.';
      return;
    }

    try {
      const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) return;
      const data = await res.json();
      if (data.ok && data.orderStatus !== startStatus) {
        clearInterval(timer);
        window.location.reload();
      }
    } catch (e) {
      // transient network problem: keep trying
    }
  }

  if (countdown) { tick(); setInterval(tick, 1000); }

  if (watching) {
    timer = setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  }
})();
