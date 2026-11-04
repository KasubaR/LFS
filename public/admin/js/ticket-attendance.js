/**
 * LFS admin — entrance attendance (admin/events/attendance.blade.php)
 *
 * Search, confirm email and ticket count, mark each arriving attendee present.
 * The server decides everything (only paid orders, only once per attendee);
 * this page only displays the result. All text is inserted with textContent.
 */
'use strict';

(function () {
  const app = document.getElementById('attendance-app');
  if (!app) return;

  const searchUrl = app.dataset.searchUrl;
  const presentUrl = app.dataset.presentUrl;
  const undoUrl = app.dataset.undoUrl;
  const minLen = parseInt(app.dataset.min || '2', 10);
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  const input = document.getElementById('att-search');
  const resultsEl = document.getElementById('att-results');
  const msgEl = document.getElementById('att-msg');
  const presentCount = document.getElementById('present-count');
  const soldCount = document.getElementById('sold-count');

  let results = [];
  let timer = null;
  let seq = 0;

  const STATUS = {
    paid: ['Paid', '#16a34a'],
    pending_payment: ['Awaiting payment', '#d97706'],
    payment_review_required: ['Payment review: not confirmed', '#dc2626'],
    cancelled: ['Cancelled / expired', '#6b7280'],
    failed: ['Failed', '#6b7280'],
  };

  function el(tag, props, children) {
    const node = document.createElement(tag);
    Object.keys(props || {}).forEach((k) => {
      if (k === 'text') node.textContent = props[k];
      else if (k === 'style') node.style.cssText = props[k];
      else node.setAttribute(k, props[k]);
    });
    (children || []).forEach((c) => node.appendChild(c));
    return node;
  }

  function clock(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function say(text, bad) {
    msgEl.textContent = text || '';
    msgEl.style.color = bad ? '#fca5a5' : 'var(--text-dim)';
  }

  function headers() {
    return { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' };
  }

  function updateStats(stats) {
    if (!stats) return;
    presentCount.textContent = stats.present;
    soldCount.textContent = stats.ticketsSold;
  }

  /* ── Search ─────────────────────────────────────────────── */

  async function runSearch() {
    const q = input.value.trim();
    if (q.length < minLen) {
      results = [];
      render();
      say(q.length ? 'Keep typing…' : '');
      return;
    }
    const mine = ++seq;
    let data;
    try {
      const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
      data = await res.json();
    } catch (e) {
      say('Could not reach the server. Check the connection.', true);
      return;
    }
    if (mine !== seq || !data.ok) return;
    results = data.results;
    updateStats(data.stats);
    say(results.length ? '' : 'No orders found for "' + q + '".');
    render();
  }

  /* ── Actions ────────────────────────────────────────────── */

  async function post(url, body) {
    const res = await fetch(url, { method: 'POST', headers: headers(), body: JSON.stringify(body || {}) });
    let data = {};
    try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
    return { status: res.status, data: data };
  }

  function replaceTicket(ticket) {
    results.forEach((o) => {
      const i = o.tickets.findIndex((t) => t.id === ticket.id);
      if (i >= 0) {
        o.tickets[i] = ticket;
        o.presentCount = o.tickets.filter((t) => t.present).length;
      }
    });
  }

  async function markPresent(ticket) {
    say('');
    let r;
    try { r = await post(presentUrl.replace('__ID__', ticket.id)); } catch (e) { say('Network error. Nothing was changed.', true); return; }
    if (r.data.ticket) replaceTicket(r.data.ticket);
    if (r.data.ok) {
      updateStats(r.data.stats);
      say(ticket.name + ' marked present.');
    } else if (r.status === 403) {
      say('You do not have permission to mark attendance.', true);
    } else {
      say(r.data.message || 'Could not mark present.', true);
    }
    render();
  }

  async function undo(ticket) {
    const reason = window.prompt('Why are you undoing "present" for ' + ticket.name + '?');
    if (reason === null) return;
    let r;
    try { r = await post(undoUrl.replace('__ID__', ticket.id), { reason: reason }); } catch (e) { say('Network error. Nothing was changed.', true); return; }
    if (r.data.ticket) replaceTicket(r.data.ticket);
    if (r.data.ok) {
      updateStats(r.data.stats);
      say('Undone for ' + ticket.name + '.');
    } else if (r.status === 403) {
      say('You do not have permission to undo attendance.', true);
    } else {
      say(r.data.message || 'Could not undo.', true);
    }
    render();
  }

  /* ── Rendering ──────────────────────────────────────────── */

  function render() {
    resultsEl.textContent = '';
    results.forEach((o) => resultsEl.appendChild(orderCard(o)));
  }

  function orderCard(o) {
    const st = STATUS[o.status] || [o.status, '#6b7280'];
    const card = el('div', { style: 'border:1px solid var(--border-mid); border-radius:12px; padding:1rem; margin-bottom:1rem; background:var(--black-soft);' });

    const head = el('div', { style: 'display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:0.5rem;' });
    const who = el('div');
    who.appendChild(el('div', { text: o.buyerName, style: 'font-size:1.15rem; font-weight:700;' }));
    who.appendChild(el('div', { text: o.buyerEmail, style: 'font-size:1rem; color:var(--green-bright); word-break:break-all;' }));
    who.appendChild(el('div', { text: o.orderNumber + ' · ' + o.quantity + ' ticket' + (o.quantity === 1 ? '' : 's') + ' bought', style: 'font-size:0.85rem; color:var(--text-dim); font-family:monospace;' }));
    head.appendChild(who);

    const right = el('div', { style: 'text-align:right;' });
    right.appendChild(el('span', { text: st[0], style: 'display:inline-block; padding:0.2rem 0.7rem; border-radius:999px; font-size:0.8rem; font-weight:700; background:' + st[1] + '22; color:' + st[1] + ';' }));
    if (o.canAdmit) {
      right.appendChild(el('div', { text: o.presentCount + ' of ' + o.quantity + ' present', style: 'margin-top:0.4rem; font-size:0.85rem; color:var(--text-dim);' }));
    }
    head.appendChild(right);
    card.appendChild(head);

    if (!o.canAdmit) {
      card.appendChild(el('div', {
        text: o.status === 'payment_review_required'
          ? 'Payment received but tickets are not confirmed. Do not admit; refer to the organisers.'
          : 'This order is not paid. Nobody can be admitted on it.',
        style: 'padding:0.5rem 0.75rem; border-radius:8px; background:#dc262622; color:#fca5a5; font-size:0.9rem; margin-bottom:0.5rem;',
      }));
    } else {
      card.appendChild(el('div', { text: 'Confirm the customer\'s email and the number of tickets above, then mark each person who arrives.', style: 'font-size:0.8rem; color:var(--text-dim); margin-bottom:0.5rem;' }));
    }

    o.tickets.forEach((t) => card.appendChild(ticketRow(t)));
    return card;
  }

  function ticketRow(t) {
    const row = el('div', { style: 'display:flex; justify-content:space-between; align-items:center; gap:0.75rem; flex-wrap:wrap; padding:0.65rem 0; border-top:1px solid var(--border-subtle);' });

    const info = el('div');
    info.appendChild(el('div', { text: t.name, style: 'font-size:1.05rem; font-weight:600;' }));
    const meta = el('div', { style: 'font-size:0.8rem; color:var(--text-dim);' });
    meta.appendChild(el('span', { text: t.reference, style: 'font-family:monospace;' }));
    if (t.email) meta.appendChild(document.createTextNode(' · ' + t.email));
    info.appendChild(meta);
    if (t.isMemberTicket) {
      info.appendChild(el('div', { text: 'Member ticket · membership no. ' + (t.membershipNumber || '') + ' · ask for card or ID', style: 'font-size:0.8rem; color:#16a34a; font-weight:600;' }));
    }
    row.appendChild(info);

    const action = el('div', { style: 'display:flex; align-items:center; gap:0.5rem;' });
    if (t.present) {
      action.appendChild(el('span', { text: 'Present at ' + clock(t.presentAt), style: 'color:#16a34a; font-weight:700;' }));
      const u = el('button', { type: 'button', text: 'Undo', style: 'padding:0.35rem 0.7rem; background:transparent; border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); cursor:pointer; font-size:0.8rem;' });
      u.addEventListener('click', () => undo(t));
      action.appendChild(u);
    } else if (t.canMark) {
      const b = el('button', { type: 'button', text: 'Mark present', style: 'padding:0.7rem 1.25rem; background:var(--flag-green); color:#fff; border:none; border-radius:10px; font-size:1rem; font-weight:700; cursor:pointer; min-width:9rem;' });
      b.addEventListener('click', () => { b.disabled = true; markPresent(t); });
      action.appendChild(b);
    } else {
      action.appendChild(el('span', { text: 'Cannot admit', style: 'color:#fca5a5; font-weight:600; font-size:0.9rem;' }));
    }
    row.appendChild(action);
    return row;
  }

  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(runSearch, 250); });
})();
