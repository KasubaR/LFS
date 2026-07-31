/**
 * dashboard.js — LFS Admin Panel
 * Client-side JS for admin layout + dashboard interactions.
 * Handles: sidebar toggle, dropdown menus, charts, live stats.
 *
 * Dependencies: Chart.js, Alpine.js (loaded in admin.layout.ejs)
 */

'use strict';

/* ══════════════════════════════════════════════
   SIDEBAR TOGGLE
════════════════════════════════════════════════ */

/**
 * Toggle the sidebar collapsed/expanded state.
 * Persists preference to localStorage.
 */
function toggleSidebar() {
  const layout  = document.getElementById('adminLayout');
  const sidebar = document.getElementById('adminSidebar');
  const btn     = document.getElementById('sidebarToggle');
  const overlay = document.getElementById('mobileOverlay');

  const isMobile = window.innerWidth <= 768;

  if (isMobile) {
    // Mobile: slide in/out
    sidebar.classList.toggle('mobile-open');
    overlay && overlay.classList.toggle('active');
  } else {
    // Desktop: collapse/expand
    const collapsed = layout.classList.toggle('sidebar-collapsed');
    btn && btn.setAttribute('aria-expanded', String(!collapsed));
    try { localStorage.setItem('lfs_sidebar_collapsed', collapsed ? '1' : '0'); } catch (_) {}
  }
}

/** Restore sidebar state on page load. */
function restoreSidebarState() {
  try {
    const collapsed = localStorage.getItem('lfs_sidebar_collapsed') === '1';
    if (collapsed && window.innerWidth > 768) {
      document.getElementById('adminLayout')?.classList.add('sidebar-collapsed');
    }
  } catch (_) {}
}

/* ══════════════════════════════════════════════
   FLASH MESSAGE AUTO-DISMISS
════════════════════════════════════════════════ */

function initFlashMessages() {
  const alerts = document.querySelectorAll('[role="alert"]');
  alerts.forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity 0.5s ease';
      el.style.opacity    = '0';
      setTimeout(function () { el.remove(); }, 520);
    }, 5000);
  });
}

/* ══════════════════════════════════════════════
   CHART REGISTRY
   Tracks Chart.js instances for update/destroy.
════════════════════════════════════════════════ */

const LFS_CHARTS = {};

/**
 * Safely destroy an existing chart before recreating.
 * @param {string} id - canvas element id
 */
function destroyChart(id) {
  if (LFS_CHARTS[id]) {
    LFS_CHARTS[id].destroy();
    delete LFS_CHARTS[id];
  }
}

/* ══════════════════════════════════════════════
   CHART DEFAULTS
════════════════════════════════════════════════ */

function applyChartDefaults() {
  if (typeof Chart === 'undefined') return;

  Chart.defaults.color           = '#999999';
  Chart.defaults.borderColor     = 'rgba(255,255,255,0.06)';
  Chart.defaults.font.family     = "'DM Sans', sans-serif";
  Chart.defaults.font.size       = 11;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.pointStyleWidth = 8;
  Chart.defaults.plugins.legend.labels.padding = 16;
}

/* ══════════════════════════════════════════════
   MAIN CHART — Members + Events
════════════════════════════════════════════════ */

/**
 * Build the main "Registrations & Attendance" combo chart.
 * @param {Object|null} chartData
 */
function buildMainChart(chartData) {
  const canvas = document.getElementById('chartMain');
  const loader = document.getElementById('chartMainLoader');

  if (!canvas || typeof Chart === 'undefined') return;

  destroyChart('chartMain');

  const labels = chartData?.members?.labels
    || ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const memberData = chartData?.members?.data
    || Array.from({ length: labels.length }, () => Math.floor(Math.random() * 30 + 10));
  const eventData  = chartData?.events?.data
    || Array.from({ length: labels.length }, () => Math.floor(Math.random() * 5 + 1));

  LFS_CHARTS.chartMain = new Chart(canvas, {
    data: {
      labels,
      datasets: [
        {
          type:            'bar',
          label:           'New Members',
          data:            memberData,
          backgroundColor: 'rgba(25,138,78,0.5)',
          borderColor:     '#198a4e',
          borderWidth:     1,
          borderRadius:    4,
          yAxisID:         'yMembers',
        },
        {
          type:        'line',
          label:       'Event Attendance',
          data:        eventData,
          borderColor: '#e07b39',
          backgroundColor: 'rgba(224,123,57,0.10)',
          borderWidth: 2,
          tension:     0.4,
          fill:        true,
          pointBackgroundColor: '#e07b39',
          pointRadius: 3,
          yAxisID:     'yEvents',
        },
      ],
    },
    options: {
      responsive:          true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          backgroundColor: '#1c1c1c',
          borderColor:     'rgba(255,255,255,0.1)',
          borderWidth:     1,
          titleColor:      '#f5f2ec',
          bodyColor:       '#999',
          padding:         10,
        },
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { maxRotation: 0 },
        },
        yMembers: {
          position: 'left',
          grid: { color: 'rgba(255,255,255,0.04)' },
          beginAtZero: true,
          ticks: { stepSize: 10 },
          title: { display: false },
        },
        yEvents: {
          position:    'right',
          grid:        { display: false },
          beginAtZero: true,
          ticks:       { stepSize: 1 },
        },
      },
    },
  });

  if (loader) loader.style.display = 'none';
}

/**
 * Update main chart when period select changes.
 * In production, fetch new data from API.
 * @param {string} months
 */
async function updateMainChart(months) {
  const loader = document.getElementById('chartMainLoader');
  if (loader) loader.style.display = 'flex';

  try {
    const res  = await fetch(`/api/admin/chart/registrations?months=${months}`);
    if (!res.ok) throw new Error('API error');
    const data = await res.json();
    buildMainChart(data);
  } catch (_) {
    // Fallback: rebuild with mock data
    buildMainChart(null);
  }
}

/* ══════════════════════════════════════════════
   SALES CHART
════════════════════════════════════════════════ */

function buildSalesChart(chartData) {
  const canvas = document.getElementById('chartSales');
  const loader = document.getElementById('chartSalesLoader');

  if (!canvas || typeof Chart === 'undefined') return;

  destroyChart('chartSales');

  const labels     = chartData?.sales?.labels     || ['Jan','Feb','Mar','Apr','May','Jun'];
  const salesData  = chartData?.sales?.data       || Array.from({ length: labels.length }, () => Math.floor(Math.random() * 5000 + 2000));
  const galleryData = chartData?.gallery?.data    || Array.from({ length: labels.length }, () => Math.floor(Math.random() * 60 + 10));

  LFS_CHARTS.chartSales = new Chart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label:           'Sales (K)',
          data:            salesData,
          borderColor:     '#c9a84c',
          backgroundColor: 'rgba(201,168,76,0.1)',
          borderWidth:     2,
          tension:         0.4,
          fill:            true,
          pointBackgroundColor: '#c9a84c',
          pointRadius:     3,
          yAxisID:         'ySales',
        },
        {
          label:           'Gallery Uploads',
          data:            galleryData,
          borderColor:     '#7ecb93',
          backgroundColor: 'rgba(126,203,147,0.08)',
          borderWidth:     1.5,
          tension:         0.4,
          fill:            false,
          borderDash:      [4, 3],
          pointRadius:     2,
          yAxisID:         'yGallery',
        },
      ],
    },
    options: {
      responsive:          true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          backgroundColor: '#1c1c1c',
          borderColor:     'rgba(255,255,255,0.1)',
          borderWidth:     1,
          titleColor:      '#f5f2ec',
          bodyColor:       '#999',
          padding:         10,
          callbacks: {
            label: function (ctx) {
              const val = ctx.parsed.y;
              return ctx.dataset.label.includes('Sales')
                ? ` K ${val.toLocaleString()}`
                : ` ${val} uploads`;
            },
          },
        },
      },
      scales: {
        x:       { grid: { display: false } },
        ySales:  { position: 'left',  grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true },
        yGallery:{ position: 'right', grid: { display: false }, beginAtZero: true },
      },
    },
  });

  if (loader) loader.style.display = 'none';
}

/* ══════════════════════════════════════════════
   MEMBERSHIP ANALYTICS CHARTS
════════════════════════════════════════════════ */

const ADMIN_TOOLTIP_STYLE = {
  backgroundColor: '#1c1c1c',
  borderColor:     'rgba(255,255,255,0.1)',
  borderWidth:     1,
  titleColor:      '#f5f2ec',
  bodyColor:       '#999',
  padding:         10,
};

function buildStatusBreakdownChart(chartData) {
  const canvas = document.getElementById('chartStatusBreakdown');
  if (!canvas || typeof Chart === 'undefined') return;
  destroyChart('chartStatusBreakdown');

  const data = chartData?.statusBreakdown;
  if (!data?.labels?.length) return;

  LFS_CHARTS.chartStatusBreakdown = new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: data.labels,
      datasets: [{
        data: data.data,
        backgroundColor: ['#198a4e', '#c0392b'],
        borderWidth: 0,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, pointStyleWidth: 8, padding: 16 } },
        tooltip: ADMIN_TOOLTIP_STYLE,
      },
    },
  });
}

function buildRevenueChart(chartData) {
  const canvas = document.getElementById('chartRevenue');
  if (!canvas || typeof Chart === 'undefined') return;
  destroyChart('chartRevenue');

  const data = chartData?.revenue;
  if (!data?.labels?.length) return;

  LFS_CHARTS.chartRevenue = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [
        {
          label: 'Shop',
          data: data.shop,
          backgroundColor: 'rgba(201,168,76,0.7)',
          borderColor: '#c9a84c',
          borderWidth: 1,
          borderRadius: 4,
        },
        {
          label: 'Membership',
          data: data.membership,
          backgroundColor: 'rgba(25,138,78,0.7)',
          borderColor: '#198a4e',
          borderWidth: 1,
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          ...ADMIN_TOOLTIP_STYLE,
          callbacks: { label: (ctx) => ` ${ctx.dataset.label}: K ${Number(ctx.parsed.y).toLocaleString()}` },
        },
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true },
      },
    },
  });
}

function buildGrowthChart(chartData) {
  const canvas = document.getElementById('chartGrowth');
  if (!canvas || typeof Chart === 'undefined') return;
  destroyChart('chartGrowth');

  const data = chartData?.growth;
  if (!data?.labels?.length) return;

  LFS_CHARTS.chartGrowth = new Chart(canvas, {
    type: 'line',
    data: {
      labels: data.labels,
      datasets: [{
        label: 'Total Members',
        data: data.data,
        borderColor: '#198a4e',
        backgroundColor: 'rgba(25,138,78,0.12)',
        borderWidth: 2,
        tension: 0.4,
        fill: true,
        pointBackgroundColor: '#198a4e',
        pointRadius: 3,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: ADMIN_TOOLTIP_STYLE,
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true },
      },
    },
  });
}

function buildRegistrationsChart(chartData) {
  const canvas = document.getElementById('chartRegistrations');
  if (!canvas || typeof Chart === 'undefined') return;
  destroyChart('chartRegistrations');

  const data = chartData?.registrations;
  if (!data?.labels?.length) return;

  LFS_CHARTS.chartRegistrations = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [{
        label: 'New Registrations',
        data: data.data,
        backgroundColor: 'rgba(224,123,57,0.7)',
        borderColor: '#e07b39',
        borderWidth: 1,
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: ADMIN_TOOLTIP_STYLE,
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true, ticks: { precision: 0 } },
      },
    },
  });
}

function buildRenewalsChart(chartData) {
  const canvas = document.getElementById('chartRenewals');
  if (!canvas || typeof Chart === 'undefined') return;
  destroyChart('chartRenewals');

  const data = chartData?.renewals;
  if (!data?.labels?.length) return;

  LFS_CHARTS.chartRenewals = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [{
        label: 'Renewals',
        data: data.data,
        backgroundColor: 'rgba(59,130,246,0.7)',
        borderColor: '#3b82f6',
        borderWidth: 1,
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: ADMIN_TOOLTIP_STYLE,
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true, ticks: { precision: 0 } },
      },
    },
  });
}

function buildSatellitesChart(chartData) {
  const canvas = document.getElementById('chartSatellites');
  if (!canvas || typeof Chart === 'undefined') return;
  destroyChart('chartSatellites');

  const data = chartData?.satellites;
  if (!data?.labels?.length) return;

  LFS_CHARTS.chartSatellites = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [{
        label: 'Members',
        data: data.data,
        backgroundColor: 'rgba(25,138,78,0.7)',
        borderColor: '#198a4e',
        borderWidth: 1,
        borderRadius: 4,
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: ADMIN_TOOLTIP_STYLE,
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true, ticks: { precision: 0 } },
        y: { grid: { display: false } },
      },
    },
  });
}

function buildGenderChart(chartData) {
  const canvas = document.getElementById('chartGender');
  if (!canvas || typeof Chart === 'undefined') return;
  destroyChart('chartGender');

  const data = chartData?.gender;
  if (!data?.labels?.length) return;

  LFS_CHARTS.chartGender = new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: data.labels,
      datasets: [{
        data: data.data,
        backgroundColor: ['#198a4e', '#e07b39', '#c9a84c', '#3b82f6', '#7ecb93'],
        borderWidth: 0,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, pointStyleWidth: 8, padding: 16 } },
        tooltip: ADMIN_TOOLTIP_STYLE,
      },
    },
  });
}

/* ══════════════════════════════════════════════
   LIVE STATS POLLING
   Refreshes metric cards every 60 seconds.
════════════════════════════════════════════════ */

let statsInterval = null;

async function fetchLiveStats() {
  try {
    const res  = await fetch('/api/admin/stats');
    if (!res.ok) return;
    const data = await res.json();

    if (!data) return;

    const map = {
      'stat-total-members':     data.totalMembers,
      'stat-active-members':    data.activeMembers,
      'stat-upcoming-events':   data.upcomingEvents,
      'stat-pending-orders':    data.pendingOrders,
      'stat-shop-revenue':      data.shopRevenue !== undefined ? `K ${Number(data.shopRevenue).toLocaleString()}` : null,
      'stat-membership-revenue': data.membershipRevenue !== undefined ? `K ${Number(data.membershipRevenue).toLocaleString()}` : null,
      'stat-gallery':           data.galleryUploads,
    };

    Object.entries(map).forEach(function ([id, val]) {
      if (val === null || val === undefined) return;
      const el = document.getElementById(id);
      if (el && String(el.textContent).trim() !== String(val)) {
        el.classList.add('stat-updating');
        el.textContent = val;
        setTimeout(function () { el.classList.remove('stat-updating'); }, 300);
      }
    });
  } catch (_) {
    // Silent fail — stale data is acceptable
  }
}

function startStatsPolling() {
  statsInterval = setInterval(fetchLiveStats, 60_000);
}

/* ══════════════════════════════════════════════
   ACTIVITY FEED — Live refresh via API
════════════════════════════════════════════════ */

async function refreshActivityFeed() {
  const feed = document.getElementById('activityFeed');
  if (!feed) return;

  try {
    const res  = await fetch('/api/admin/activity?limit=8');
    if (!res.ok) return;
    const data = await res.json();

    if (!data || !data.items) return;

    const iconMap = {
      member:  'fas fa-user-plus',
      order:   'fas fa-bag-shopping',
      event:   'fas fa-calendar-plus',
      gallery: 'fas fa-images',
    };

    feed.innerHTML = data.items.map(function (item) {
      const iconClass = iconMap[item.type] || 'fas fa-circle-dot';
      return `
        <div class="activity-item">
          <div class="activity-icon activity-icon--${item.type}" aria-hidden="true">
            <i class="${iconClass}"></i>
          </div>
          <div class="activity-content">
            <p class="activity-title">${escapeHtml(item.title)}</p>
            <p class="activity-sub">${escapeHtml(item.subtitle)}</p>
          </div>
          <time class="activity-time" datetime="${item.isoDate}">${escapeHtml(item.timeAgo)}</time>
        </div>
      `;
    }).join('');
  } catch (_) {
    // Keep server-rendered fallback
  }
}

/* ══════════════════════════════════════════════
   KEYBOARD NAVIGATION
════════════════════════════════════════════════ */

function initKeyboardNav() {
  document.addEventListener('keydown', function (e) {
    // Escape: close dropdowns / mobile sidebar
    if (e.key === 'Escape') {
      document.querySelectorAll('.admin-dropdown.open').forEach(function (d) {
        d.classList.remove('open');
      });
      // Mobile close
      document.getElementById('adminSidebar')?.classList.remove('mobile-open');
      document.getElementById('mobileOverlay')?.classList.remove('active');
    }
    // [ — toggle sidebar
    if (e.key === '[' && !e.target.matches('input, textarea, select')) {
      toggleSidebar();
    }
  });
}

/* ══════════════════════════════════════════════
   DATATABLES INIT
════════════════════════════════════════════════ */

/**
 * Initialise DataTables on any table with data-datatable attribute.
 * Called after DOM ready.
 */
function initDataTables() {
  if (typeof $.fn === 'undefined' || typeof $.fn.DataTable === 'undefined') return;

  document.querySelectorAll('table[data-datatable]').forEach(function (table) {
    $(table).DataTable({
      pageLength: 25,
      language: {
        search:      '',
        searchPlaceholder: 'Search…',
        lengthMenu:  '_MENU_ per page',
        info:        'Showing _START_–_END_ of _TOTAL_',
        paginate: {
          previous: '<i class="fas fa-chevron-left"></i>',
          next:     '<i class="fas fa-chevron-right"></i>',
        },
      },
      dom: 'frtip',
      responsive: true,
    });
  });
}

/* ══════════════════════════════════════════════
   UTILS
════════════════════════════════════════════════ */

/**
 * Escape HTML entities to prevent XSS in dynamic content.
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

/**
 * Format a number as Zambian Kwacha shorthand.
 * e.g. 12500 → "K 12.5K"
 * @param {number} n
 * @returns {string}
 */
function formatKwacha(n) {
  if (n >= 1_000_000) return `K ${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000)     return `K ${(n / 1_000).toFixed(1)}K`;
  return `K ${n}`;
}

/* ══════════════════════════════════════════════
   DASHBOARD INIT
════════════════════════════════════════════════ */

function initDashboard() {
  // Read server-injected chart data
  let chartData = null;
  try {
    const raw = document.getElementById('dashboardData')?.textContent;
    if (raw) chartData = JSON.parse(raw).chartData;
  } catch (_) {}

  applyChartDefaults();

  // Use requestIdleCallback for deferred chart rendering
  const defer = window.requestIdleCallback || function (cb) { setTimeout(cb, 100); };

  defer(function () {
    buildMainChart(chartData);
    buildSalesChart(chartData);
  });

  fetchLiveStats();
  startStatsPolling();

  // Refresh activity every 30s
  setInterval(refreshActivityFeed, 30_000);
}

/**
 * Init the membership analytics charts on the Members page.
 */
function initMemberCharts() {
  let chartData = null;
  try {
    const raw = document.getElementById('memberChartsData')?.textContent;
    if (raw) chartData = JSON.parse(raw).chartData;
  } catch (_) {}

  applyChartDefaults();

  const defer = window.requestIdleCallback || function (cb) { setTimeout(cb, 100); };

  defer(function () {
    buildStatusBreakdownChart(chartData);
    buildRevenueChart(chartData);
    buildGrowthChart(chartData);
    buildRegistrationsChart(chartData);
    buildRenewalsChart(chartData);
    buildSatellitesChart(chartData);
    buildGenderChart(chartData);
  });
}

/**
 * Reply form character counter (admin/messages/:id/reply).
 */
function initReplyCharCount() {
  const field   = document.getElementById('reply_message');
  const counter = document.getElementById('replyCharCount');
  if (!field || !counter) return;

  const max = Number(field.getAttribute('maxlength')) || 5000;

  const update = function () {
    const len = field.value.length;
    counter.textContent = `${len} / ${max}`;
  };

  field.addEventListener('input', update);
  update();
}

/* ══════════════════════════════════════════════
   DOM READY
════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function () {
  restoreSidebarState();
  initFlashMessages();
  initKeyboardNav();
  initDataTables();
  initReplyCharCount();

  // Only run dashboard-specific init if on dashboard page
  if (document.getElementById('dashboardData')) {
    initDashboard();
  }

  // Only run member-charts init if on the members page
  if (document.getElementById('memberChartsData')) {
    initMemberCharts();
  }
});

/* ══════════════════════════════════════════════
   PUBLIC API
   Expose select functions for inline event handlers.
════════════════════════════════════════════════ */

window.toggleSidebar    = toggleSidebar;
window.updateMainChart  = updateMainChart;
window.formatKwacha     = formatKwacha;
