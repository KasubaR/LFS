/**
 * LFS — event ticket checkout (pages/event-tickets.blade.php)
 *
 * Everything priced here is DISPLAY ONLY. The server re-prices the order and
 * refuses to charge if the total changed since the buyer last saw it.
 */
'use strict';

(function () {
  const form = document.getElementById('ticket-form');
  if (!form) return;

  const els = {
    quantity: document.getElementById('quantity'),
    attending: document.getElementById('buyerAttending'),
    attendees: document.getElementById('attendees'),
    memberStatus: document.getElementById('member-status'),
    memberNumber: document.getElementById('membershipNumber'),
    memberEmail: document.getElementById('membershipEmail'),
    memberApply: document.getElementById('member-apply'),
    memberLookup: document.getElementById('member-lookup'),
    buyerName: document.getElementById('buyerName'),
    buyerEmail: document.getElementById('buyerEmail'),
    buyerPhone: document.getElementById('buyerPhone'),
    payerPhone: document.getElementById('payerPhone'),
    summaryLines: document.getElementById('summary-lines'),
    summaryTotal: document.getElementById('summary-total'),
    summaryJoin: document.getElementById('summary-join'),
    payBtn: document.getElementById('pay-btn'),
    payLabel: document.getElementById('pay-label'),
    error: document.getElementById('ticket-error'),
    errorText: document.getElementById('ticket-error-text'),
    priceChanged: document.getElementById('price-changed'),
    overlay: document.querySelector('.checkout-loading'),
    overlayMsg: document.querySelector('.checkout-loading__msg'),
  };

  const cfg = {
    quoteUrl: form.dataset.quoteUrl,
    orderUrl: form.dataset.orderUrl,
    csrf: form.dataset.csrf,
    saving: parseFloat(form.dataset.saving || '0'),
    registerUrl: form.dataset.registerUrl,
    accountUrl: form.dataset.accountUrl,
    loggedIn: form.dataset.loggedIn === '1',
  };

  const token = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2);
  let quote = null;          // last server quote
  let memberName = null;     // name from the membership record, when verified
  let quoteTimer = null;
  let quoteSeq = 0;
  let submitting = false;

  const money = (n) => 'K' + Number(n).toLocaleString('en-US', {
    minimumFractionDigits: Number.isInteger(n) ? 0 : 2,
    maximumFractionDigits: 2,
  });

  /* ── Attendee rows ─────────────────────────────────────────── */

  // Typed values survive re-renders (quantity / checkbox changes).
  const typed = []; // [{name, email}]

  function readTyped() {
    els.attendees.querySelectorAll('.tk-attendee').forEach((row, i) => {
      const name = row.querySelector('[data-f="name"]');
      const email = row.querySelector('[data-f="email"]');
      if (!typed[i]) typed[i] = { name: '', email: '' };
      if (name && !name.readOnly) typed[i].name = name.value;
      if (email) typed[i].email = email.value;
    });
  }

  function renderAttendees() {
    readTyped();
    const qty = parseInt(els.quantity.value, 10) || 1;
    const attending = els.attending.checked;
    els.attendees.textContent = '';

    for (let i = 0; i < qty; i++) {
      const isBuyerSlot = i === 0 && attending;
      const isMemberSlot = isBuyerSlot && quote && quote.pricing.isMemberPricing;
      const row = document.createElement('div');
      row.className = 'tk-attendee';

      const head = document.createElement('div');
      head.className = 'tk-attendee__head';
      const title = document.createElement('span');
      title.textContent = isBuyerSlot ? 'Ticket 1 — for you' : 'Ticket ' + (i + 1);
      head.appendChild(title);
      if (isMemberSlot) {
        const badge = document.createElement('span');
        badge.className = 'tk-attendee__badge';
        badge.textContent = 'Member price';
        head.appendChild(badge);
      }
      row.appendChild(head);

      const fields = document.createElement('div');
      fields.className = 'tk-attendee__row';

      const name = document.createElement('input');
      name.className = 'checkout-form__input';
      name.type = 'text';
      name.dataset.f = 'name';
      name.maxLength = 100;
      name.autocomplete = 'off';
      if (isMemberSlot) {
        name.value = memberName || '';
        name.readOnly = true;
        name.title = 'Taken from your membership record';
        name.setAttribute('aria-label', 'Ticket 1 attendee name (from your membership record)');
      } else {
        name.placeholder = isBuyerSlot ? 'Your full name' : 'Attendee full name';
        name.value = (typed[i] && typed[i].name) || (isBuyerSlot ? els.buyerName.value : '');
        name.setAttribute('aria-label', 'Ticket ' + (i + 1) + ' attendee name');
      }
      fields.appendChild(name);

      const email = document.createElement('input');
      email.className = 'checkout-form__input';
      email.type = 'email';
      email.dataset.f = 'email';
      email.placeholder = 'Attendee email (optional)';
      email.value = (typed[i] && typed[i].email) || '';
      email.setAttribute('aria-label', 'Ticket ' + (i + 1) + ' attendee email, optional');
      fields.appendChild(email);

      row.appendChild(fields);
      els.attendees.appendChild(row);
    }
  }

  /* ── Server quote → summary ────────────────────────────────── */

  function quoteBody() {
    return {
      quantity: parseInt(els.quantity.value, 10) || 1,
      buyerAttending: els.attending.checked,
      membershipNumber: els.memberNumber ? els.memberNumber.value.trim() : '',
      membershipEmail: els.memberEmail ? els.memberEmail.value.trim() : '',
    };
  }

  function requote(immediate) {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(doQuote, immediate ? 0 : 250);
  }

  async function doQuote() {
    const seq = ++quoteSeq;
    let data;
    try {
      const res = await fetch(cfg.quoteUrl, {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify(quoteBody()),
      });
      data = await res.json();
    } catch (e) {
      return; // keep the last known summary
    }
    if (seq !== quoteSeq || !data || !data.ok) return;

    quote = data;
    memberName = data.pricing.isMemberPricing ? data.pricing.memberName : null;
    renderSummary();
    renderMemberStatus();
    renderAttendees();
  }

  function renderSummary() {
    const p = quote.pricing;
    els.summaryLines.textContent = '';
    p.lines.forEach((l) => {
      const row = document.createElement('div');
      row.className = 'checkout-summary__row';
      const left = document.createElement('span');
      left.textContent = l.label + ' × ' + l.quantity;
      const right = document.createElement('span');
      right.textContent = money(l.amount);
      row.appendChild(left);
      row.appendChild(right);
      els.summaryLines.appendChild(row);
    });
    els.summaryTotal.textContent = money(p.total);
    els.payLabel.textContent = quote.available ? 'Pay ' + money(p.total) : 'Not available';
    els.payBtn.disabled = !quote.available || submitting;

    // Join prompt: non-members are shown what membership would save.
    const showJoin = !p.isMemberPricing && cfg.saving > 0 &&
      ['guest', 'no_membership', 'verification_failed'].includes(p.discountDeniedReason);
    els.summaryJoin.style.display = showJoin ? '' : 'none';
    els.summaryJoin.textContent = '';
    if (showJoin) {
      const a = document.createElement('a');
      a.href = cfg.registerUrl;
      a.textContent = 'Join now and save ' + money(cfg.saving);
      els.summaryJoin.appendChild(a);
      els.summaryJoin.appendChild(document.createTextNode(' on your own ticket.'));
    }

    if (!quote.available) {
      showError(quote.availabilityMessage);
    } else {
      hideError();
    }
  }

  function renderMemberStatus() {
    const p = quote.pricing;
    const box = els.memberStatus;
    box.textContent = '';
    box.className = 'tk-member-status';

    if (p.isMemberPricing) {
      box.classList.add('tk-member-status--ok');
      box.textContent = 'Member price applied to your own ticket (' + p.memberName + '). Additional tickets are the standard price.';
      return;
    }

    const typedProof = els.memberNumber && els.memberNumber.value.trim() !== '';
    const note = {
      not_attending: 'The member price only applies to a ticket for you. Tick the box below to use it.',
      membership_inactive: null,
      already_claimed: 'You already hold the member-price ticket for this event. Extra tickets are the standard price.',
      name_incomplete: 'The name on your membership record looks incomplete, so the member price cannot be applied. Please update your profile first.',
      verification_failed: typedProof ? "We couldn't match that membership number and email. Check them, or log in." : null,
    }[p.discountDeniedReason];

    box.classList.add('tk-member-status--info');
    if (p.discountDeniedReason === 'membership_inactive') {
      box.appendChild(document.createTextNode("Your membership isn't active, so the member price can't be applied. "));
      const a = document.createElement('a');
      a.href = cfg.accountUrl;
      a.textContent = 'Renew your membership';
      box.appendChild(a);
      box.appendChild(document.createTextNode(' to get it.'));
    } else if (note) {
      box.textContent = note;
    }
  }

  /* ── Submit ────────────────────────────────────────────────── */

  function jsonHeaders() {
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': cfg.csrf,
      'X-Requested-With': 'XMLHttpRequest',
    };
  }

  function showError(msg) {
    els.errorText.textContent = msg;
    els.error.style.display = '';
  }

  function hideError() {
    els.error.style.display = 'none';
  }

  function setBusy(busy, msg) {
    submitting = busy;
    els.payBtn.disabled = busy || (quote && !quote.available);
    if (els.overlay) els.overlay.classList.toggle('is-active', busy);
    if (els.overlayMsg && msg) els.overlayMsg.textContent = msg;
  }

  function collect() {
    readTyped();
    const attending = els.attending.checked;
    const qty = parseInt(els.quantity.value, 10) || 1;
    const isMember = quote && quote.pricing.isMemberPricing;
    const attendees = [];
    for (let i = 0; i < qty; i++) {
      const t = typed[i] || { name: '', email: '' };
      const memberSlot = i === 0 && attending && isMember;
      attendees.push({
        // The member ticket name is never sent from the browser; the server fills it from the record.
        name: memberSlot ? '' : t.name.trim(),
        email: (t.email || '').trim(),
      });
    }
    return {
      buyerName: els.buyerName.value.trim(),
      buyerEmail: els.buyerEmail.value.trim(),
      buyerPhone: els.buyerPhone.value.trim(),
      payerPhone: els.payerPhone.value.trim(),
      provider: (form.querySelector('input[name="provider"]:checked') || {}).value || '',
      quantity: qty,
      buyerAttending: attending,
      attendees: attendees,
      membershipNumber: els.memberNumber ? els.memberNumber.value.trim() : '',
      membershipEmail: els.memberEmail ? els.memberEmail.value.trim() : '',
      expectedTotal: quote ? quote.pricing.total : null,
      idempotencyToken: token,
    };
  }

  async function onSubmit(ev) {
    ev.preventDefault();
    if (submitting) return;
    hideError();
    els.priceChanged.style.display = 'none';

    const body = collect();
    if (!body.buyerName || !body.buyerEmail || !body.payerPhone) {
      showError('Please fill in your name, email and the mobile money number to charge.');
      return;
    }
    if (body.attendees.some((a, i) => a.name === '' && !(i === 0 && body.buyerAttending))) {
      showError('Please enter a name for every ticket.');
      return;
    }

    setBusy(true, 'Sending payment request…');
    let res; let data;
    try {
      res = await fetch(cfg.orderUrl, { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(body) });
      data = await res.json();
    } catch (e) {
      setBusy(false);
      showError('Network error. Please check your connection and try again.');
      return;
    }

    if (data.ok) {
      window.location.href = data.redirect;
      return;
    }

    setBusy(false);

    if (data.reason === 'price_changed' && data.pricing) {
      quote = Object.assign({}, quote || {}, { pricing: data.pricing, available: true });
      renderSummary();
      renderMemberStatus();
      renderAttendees();
      els.priceChanged.style.display = '';
      return;
    }

    if (data.reason === 'payment_unknown' && data.redirect) {
      // The request may have reached the payment provider: do not let the buyer resubmit.
      setBusy(true, 'Checking your payment…');
      showError(data.message);
      setTimeout(() => { window.location.href = data.redirect; }, 3500);
      return;
    }

    showError(data.message || 'Could not place your order. Please try again.');
    requote(true);
  }

  /* ── Wire up ───────────────────────────────────────────────── */

  els.quantity.addEventListener('change', () => { renderAttendees(); requote(); });
  els.attending.addEventListener('change', () => { renderAttendees(); requote(); });
  if (els.memberApply) els.memberApply.addEventListener('click', () => requote(true));
  els.buyerPhone.addEventListener('change', () => { if (!els.payerPhone.value) els.payerPhone.value = els.buyerPhone.value; });
  form.addEventListener('submit', onSubmit);

  renderAttendees();
  requote(true);
})();
