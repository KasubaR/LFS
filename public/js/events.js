/* ============================================================
   LFS — Lusaka Fitness Squad | events.js
   Events page: client-side filtering & load more.
   ============================================================ */

'use strict';

/* ─────────────────────────────────────────────────────────────
   FILTER STATE
───────────────────────────────────────────────────────────── */
const filters = {
  category: '',
  distance: '',
  year:     '',
  location: '',
};

/* ─────────────────────────────────────────────────────────────
   LOAD MORE STATE
───────────────────────────────────────────────────────────── */
const PAGE_SIZE = 6;
let upcomingVisible = PAGE_SIZE;
let pastVisible     = PAGE_SIZE;

function normalise(str) {
  return (str || '').toLowerCase().trim();
}

function cardMatchesFilters(card) {
  const cat      = normalise(card.dataset.category);
  const dist     = normalise(card.dataset.distance);
  const yr       = normalise(card.dataset.year);
  const loc      = normalise(card.dataset.location);

  if (filters.category && !cat.includes(normalise(filters.category))) return false;
  if (filters.distance  && !dist.includes(normalise(filters.distance)))  return false;
  if (filters.year      && yr !== normalise(filters.year))               return false;
  if (filters.location  && !loc.includes(normalise(filters.location)))   return false;

  return true;
}

/* ─────────────────────────────────────────────────────────────
   RENDER — apply filter + slice to visible count
───────────────────────────────────────────────────────────── */
function render() {
  const upcomingCards = $$('.event-card[data-type="upcoming"]');
  const pastCards     = $$('.past-event-card');

  // Upcoming
  let upcomingShown = 0;
  upcomingCards.forEach(card => {
    const matches = cardMatchesFilters(card);
    const withinSlice = matches && upcomingShown < upcomingVisible;
    card.hidden = !withinSlice;
    if (withinSlice) upcomingShown++;
    if (matches) card.removeAttribute('data-filtered'); else card.dataset.filtered = '1';
  });

  const totalUpcomingMatched = upcomingCards.filter(c => !c.dataset.filtered).length;

  // Past
  let pastShown = 0;
  pastCards.forEach(card => {
    const matches = cardMatchesFilters(card);
    const withinSlice = matches && pastShown < pastVisible;
    card.hidden = !withinSlice;
    if (withinSlice) pastShown++;
    if (matches) card.removeAttribute('data-filtered'); else card.dataset.filtered = '1';
  });

  const totalPastMatched = pastCards.filter(c => !c.dataset.filtered).length;

  // Empty states
  const upcomingEmpty = $('#upcoming-empty');
  const pastEmpty     = $('#past-empty');

  if (upcomingEmpty) upcomingEmpty.hidden = totalUpcomingMatched > 0;
  if (pastEmpty)     pastEmpty.hidden     = totalPastMatched > 0;

  // Load more buttons
  const upcomingLoadMore = $('#upcoming-load-more');
  const pastLoadMore     = $('#past-load-more');

  if (upcomingLoadMore) upcomingLoadMore.hidden = upcomingVisible >= totalUpcomingMatched;
  if (pastLoadMore)     pastLoadMore.hidden     = pastVisible     >= totalPastMatched;

  // Result count in filter bar
  const total = totalUpcomingMatched + totalPastMatched;
  const countEl = $('#events-filter-count');
  if (countEl) countEl.textContent = `${total} event${total !== 1 ? 's' : ''}`;
  const countToggleEl = $('#events-filter-count-toggle');
  if (countToggleEl) countToggleEl.textContent = total;
}

/* ─────────────────────────────────────────────────────────────
   FILTER BAR TOGGLE (mobile)
───────────────────────────────────────────────────────────── */
function initFilterBarToggle() {
  const bar = document.getElementById('events-filter-bar');
  const toggleBtn = document.getElementById('events-filter-toggle');
  const panel = document.getElementById('events-filter-panel');
  if (!bar || !toggleBtn || !panel) return;

  toggleBtn.addEventListener('click', function () {
    const isOpen = bar.classList.toggle('events-filter-bar--open');
    toggleBtn.setAttribute('aria-expanded', isOpen);
  });
}

/* ─────────────────────────────────────────────────────────────
   INIT FILTERS
───────────────────────────────────────────────────────────── */
function initFilters() {
  const selects = $$('.events-filter-bar__select');
  selects.forEach(select => {
    select.addEventListener('change', () => {
      const key = select.dataset.filter;
      if (key in filters) {
        filters[key] = select.value;
        upcomingVisible = PAGE_SIZE;
        pastVisible     = PAGE_SIZE;
        render();
      }
    });
  });

  const clearBtn = $('#events-filter-clear');
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      selects.forEach(s => { s.value = ''; });
      Object.keys(filters).forEach(k => { filters[k] = ''; });
      upcomingVisible = PAGE_SIZE;
      pastVisible     = PAGE_SIZE;
      render();
    });
  }
}

/* ─────────────────────────────────────────────────────────────
   INIT LOAD MORE
───────────────────────────────────────────────────────────── */
function initLoadMore() {
  const upcomingBtn = $('#upcoming-load-more');
  const pastBtn     = $('#past-load-more');

  if (upcomingBtn) {
    upcomingBtn.addEventListener('click', () => {
      upcomingVisible += PAGE_SIZE;
      render();
    });
  }

  if (pastBtn) {
    pastBtn.addEventListener('click', () => {
      pastVisible += PAGE_SIZE;
      render();
    });
  }
}

/* ─────────────────────────────────────────────────────────────
   ROUTE MAP LIGHTBOX (event detail page)
───────────────────────────────────────────────────────────── */
function initRouteLightbox() {
  const triggers = $$('.event-detail-route__img-wrap--zoomable');
  if (!triggers.length) return;

  // Build overlay
  const overlay = document.createElement('div');
  overlay.className = 'lb-overlay is-hidden';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Route map zoom');
  overlay.innerHTML = `
    <button class="lb-close" aria-label="Close"><i class="fas fa-xmark"></i></button>
    <div class="lb-content">
      <img src="" alt="" style="max-width:92vw;max-height:88vh;object-fit:contain;border-radius:6px;">
    </div>`;
  document.body.appendChild(overlay);

  const img   = overlay.querySelector('img');
  const close = overlay.querySelector('.lb-close');

  function open(src, alt) {
    img.src = src;
    img.alt = alt || '';
    overlay.classList.remove('is-hidden');
    document.body.style.overflow = 'hidden';
    close.focus();
  }

  function closeLb() {
    overlay.classList.add('is-hidden');
    document.body.style.overflow = '';
  }

  triggers.forEach(el => {
    el.addEventListener('click', () => open(el.dataset.zoomSrc, el.dataset.zoomAlt));
    el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(el.dataset.zoomSrc, el.dataset.zoomAlt); } });
  });

  close.addEventListener('click', closeLb);
  overlay.addEventListener('click', e => { if (e.target === overlay) closeLb(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLb(); });
}

/* ─────────────────────────────────────────────────────────────
   BOOT
───────────────────────────────────────────────────────────── */
/* ─────────────────────────────────────────────────────────────
   COPY LINK (event detail page)
───────────────────────────────────────────────────────────── */
function initCopyLink() {
  const btn = $('[data-copy-link]');
  if (!btn || !navigator.clipboard) return;
  btn.addEventListener('click', () => {
    navigator.clipboard.writeText(location.href).then(() => {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
      setTimeout(() => { btn.innerHTML = orig; }, 2000);
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initFilterBarToggle();
  initFilters();
  initLoadMore();
  render();
  initCopyLink();
  initRouteLightbox();
});
