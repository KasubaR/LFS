@extends('layouts.admin')

@section('content')
@php
$pageTitle   = 'Dashboard';
$activePage  = 'dashboard';
$breadcrumbs = [];
@endphp

<!-- ══════════════════════════════════════════════
     1. STATS CARDS
════════════════════════════════════════════════ -->
<section class="stats-grid" aria-label="Key metrics">

  <!-- New Messages -->
  <article class="stat-card stat-card--green" aria-label="New contact messages">
    <div class="stat-card__icon" aria-hidden="true"><i class="fas fa-envelope"></i></div>
    <p class="stat-card__label">New Messages</p>
    <p class="stat-card__value" id="stat-new-messages">
      {{ isset($stats) ? number_format($stats['newMessages']) : '—' }}
    </p>
    <div class="stat-card__meta">
      <span class="stat-card__meta-text">Contact form</span>
    </div>
  </article>

  <!-- Upcoming Events -->
  <article class="stat-card stat-card--red" aria-label="Upcoming events in next 30 days">
    <div class="stat-card__icon" aria-hidden="true"><i class="fas fa-calendar-days"></i></div>
    <p class="stat-card__label">Upcoming Events</p>
    <p class="stat-card__value" id="stat-upcoming-events">
      {{ isset($stats) ? $stats['upcomingEvents'] : '—' }}
    </p>
    <div class="stat-card__meta">
      <span style="font-size:0.68rem; color:var(--text-dim);">Next 30 days</span>
    </div>
  </article>

  <!-- Pending Orders -->
  <article class="stat-card stat-card--orange" aria-label="Pending shop orders">
    <div class="stat-card__icon" aria-hidden="true"><i class="fas fa-bag-shopping"></i></div>
    <p class="stat-card__label">Pending Orders</p>
    <p class="stat-card__value" id="stat-pending-orders">
      {{ isset($stats) ? $stats['pendingOrders'] : '—' }}
    </p>
    <div class="stat-card__meta">
      @if(isset($stats) && $stats['pendingOrders'] > 0)
        <span class="stat-card__trend stat-card__trend--down" aria-label="Needs attention">
          <i class="fas fa-circle-exclamation" aria-hidden="true"></i> Action needed
        </span>
      @else
        <span style="font-size:0.68rem;color:var(--text-dim);">All clear</span>
      @endif
    </div>
  </article>

  <!-- Shop Revenue -->
  <article class="stat-card stat-card--gold" aria-label="Shop revenue this month">
    <div class="stat-card__icon" aria-hidden="true"><i class="fas fa-bag-shopping"></i></div>
    <p class="stat-card__label">Shop Revenue</p>
    <p class="stat-card__value" id="stat-shop-revenue" style="font-size:1.6rem; letter-spacing:0.01em;">
      K {{ isset($stats['shopRevenue']) ? number_format($stats['shopRevenue']) : '—' }}
    </p>
    <div class="stat-card__meta">
      <span style="font-size:0.68rem;color:var(--text-dim);">This month</span>
    </div>
  </article>

  <!-- Membership Revenue -->
  <article class="stat-card stat-card--gold" aria-label="Membership revenue this month">
    <div class="stat-card__icon" aria-hidden="true"><i class="fas fa-id-card"></i></div>
    <p class="stat-card__label">Membership Revenue</p>
    <p class="stat-card__value" id="stat-membership-revenue" style="font-size:1.6rem; letter-spacing:0.01em;">
      K {{ isset($stats['membershipRevenue']) ? number_format($stats['membershipRevenue']) : '—' }}
    </p>
    <div class="stat-card__meta">
      <span style="font-size:0.68rem;color:var(--text-dim);">This month</span>
    </div>
  </article>

  <!-- Total Members -->
  <article class="stat-card stat-card--green" aria-label="Total registered members">
    <div class="stat-card__icon" aria-hidden="true"><i class="fas fa-users"></i></div>
    <p class="stat-card__label">Total Members</p>
    <p class="stat-card__value" id="stat-total-members">
      {{ isset($stats['totalMembers']) ? number_format($stats['totalMembers']) : '—' }}
    </p>
    <div class="stat-card__meta">
      @if(isset($stats['newMembersThisMonth']) && $stats['newMembersThisMonth'] > 0)
        <span class="stat-card__trend stat-card__trend--up" aria-label="{{ $stats['newMembersThisMonth'] }} new this month">
          <i class="fas fa-arrow-trend-up" aria-hidden="true"></i> +{{ $stats['newMembersThisMonth'] }} this month
        </span>
      @else
        <span style="font-size:0.68rem;color:var(--text-dim);">No new members this month</span>
      @endif
    </div>
  </article>

  <!-- Active Members -->
  <article class="stat-card stat-card--green" aria-label="Active members this month">
    <div class="stat-card__icon" aria-hidden="true"><i class="fas fa-user-check"></i></div>
    <p class="stat-card__label">Active Members</p>
    <p class="stat-card__value" id="stat-active-members">
      {{ isset($stats['activeMembers']) ? number_format($stats['activeMembers']) : '—' }}
    </p>
    <div class="stat-card__meta">
      <span style="font-size:0.68rem;color:var(--text-dim);">This month</span>
    </div>
  </article>

</section>


<!-- ══════════════════════════════════════════════
     2. QUICK ACTIONS
════════════════════════════════════════════════ -->
<section class="quick-actions" aria-label="Quick actions">
  <a href="/admin/events/create"   class="btn-quick btn-quick--green">
    <i class="fas fa-plus" aria-hidden="true"></i> Add Event
  </a>
  <a href="/admin/products/create" class="btn-quick btn-quick--outline">
    <i class="fas fa-plus" aria-hidden="true"></i> Add Product
  </a>
  <a href="/admin/blog/create"     class="btn-quick btn-quick--outline">
    <i class="fas fa-plus" aria-hidden="true"></i> Add Blog
  </a>
  <a href="/admin/gallery/upload"  class="btn-quick btn-quick--outline">
    <i class="fas fa-upload" aria-hidden="true"></i> Upload Gallery
  </a>
</section>


<!-- ══════════════════════════════════════════════
     3. UPCOMING EVENTS
════════════════════════════════════════════════ -->
<section class="admin-panel" aria-label="Upcoming events">
  <div class="admin-panel__header">
    <h2 class="admin-panel__title">
      <span class="admin-panel__title-dot" style="background:var(--flag-red);" aria-hidden="true"></span>
      Upcoming Events
    </h2>
    <a href="/admin/events/list" class="admin-panel__action">View all →</a>
  </div>
  <div class="admin-panel__body">
    @if(!empty($upcomingEvents))
      <ul class="upcoming-events" aria-label="Next scheduled events">
        @foreach($upcomingEvents as $ev)
          <li class="upcoming-events__item">
            <div class="upcoming-events__main">
              <span class="upcoming-events__title">{{ $ev['title'] }}</span>
              <span class="upcoming-events__meta">
                <i class="fas fa-calendar-day" aria-hidden="true"></i>
                @if(!empty($ev['eventDate']))
                  {{ date('j M Y', strtotime($ev['eventDate'])) }}
                @else
                  TBA
                @endif
                @if(!empty($ev['location']))
                  &nbsp;·&nbsp;
                  <i class="fas fa-location-dot" aria-hidden="true"></i>
                  {{ $ev['location'] }}
                @endif
              </span>
            </div>
            <div class="upcoming-events__actions">
              <a href="/events/{{ $ev['slug'] ?? $ev['id'] }}"
                 class="admin-btn admin-btn--ghost admin-btn--sm" target="_blank" rel="noopener">
                View
              </a>
              <a href="/admin/events/{{ $ev['id'] }}/edit"
                 class="admin-btn admin-btn--primary admin-btn--sm">
                Edit
              </a>
            </div>
          </li>
        @endforeach
      </ul>
    @else
      <p class="upcoming-events__empty">
        No upcoming events scheduled. Create a new event to see it here.
      </p>
    @endif
  </div>
</section>


<!-- ══════════════════════════════════════════════
     4. CHARTS ROW
════════════════════════════════════════════════ -->
<section class="charts-grid" aria-label="Analytics charts">

  <!-- Sales performance -->
  <div class="admin-panel" aria-label="Sales performance">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-orange);" aria-hidden="true"></span>
        Sales Performance
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <div class="loading-overlay" id="chartSalesLoader" aria-label="Loading chart">
          <div class="loading-spinner"></div>
        </div>
        <canvas id="chartSales" aria-label="Sales performance chart"></canvas>
      </div>
    </div>
  </div>

</section>


<!-- ══════════════════════════════════════════════
     5. RECENT ACTIVITY + PENDING TASKS
════════════════════════════════════════════════ -->
<section class="content-grid" aria-label="Activity and tasks">

  <!-- Recent Activity Feed -->
  <div class="admin-panel" aria-label="Recent activity">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-green);" aria-hidden="true"></span>
        Recent Activity
      </h2>
      <a href="{{ url('/admin/activity') }}" class="admin-panel__action" aria-label="View all activity">View all →</a>
    </div>
    <div class="admin-panel__body" style="padding:0 1.25rem;" id="activityFeed">

      @if(!empty($recentActivity))
        @foreach(array_slice($recentActivity, 0, 8) as $item)
          <div class="activity-item">
            <div class="activity-icon activity-icon--{{ $item['type'] }}" aria-hidden="true">
              <i class="{{ $item['icon'] }}"></i>
            </div>
            <div class="activity-content">
              <p class="activity-title">{{ $item['title'] }}</p>
              <p class="activity-sub">{{ $item['subtitle'] }}</p>
            </div>
            <time class="activity-time" datetime="{{ $item['isoDate'] }}">{{ $item['timeAgo'] }}</time>
          </div>
        @endforeach
      @else
        <!-- Skeleton placeholders while JS loads -->
        @for($i = 0; $i < 5; $i++)
          <div class="activity-item" aria-hidden="true">
            <div class="skeleton" style="width:32px;height:32px;border-radius:8px;flex-shrink:0;"></div>
            <div class="activity-content">
              <div class="skeleton" style="height:12px;width:60%;margin-bottom:6px;"></div>
              <div class="skeleton" style="height:10px;width:40%;"></div>
            </div>
            <div class="skeleton" style="height:10px;width:40px;"></div>
          </div>
        @endfor
      @endif

    </div>
  </div>


  <!-- Pending Tasks -->
  <div class="admin-panel" aria-label="Pending tasks">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-orange);" aria-hidden="true"></span>
        Pending Tasks
      </h2>
      <span style="font-size:0.7rem;color:var(--text-dim);">Needs attention</span>
    </div>
    <div class="admin-panel__body" style="padding:0 1.25rem;">

      @php
        $tasks = $pendingTasks ?? [];
        $orderCount = (int) ($tasks['orders'] ?? 0);
        $messageCount = (int) ($tasks['messages'] ?? 0);
        $membershipCount = (int) ($tasks['memberships'] ?? 0);
      @endphp

      @if($orderCount > 0)
        <div class="task-item">
          <div class="task-priority task-priority--high" aria-hidden="true"></div>
          <span class="task-label">
            <i class="fas fa-bag-shopping" style="margin-right:0.4rem;opacity:0.5;" aria-hidden="true"></i>
            Orders to process
          </span>
          <span class="task-count" aria-label="{{ $orderCount }} orders">{{ $orderCount }}</span>
          <a href="/admin/orders?status=pending" class="task-action" aria-label="Process pending orders">Process →</a>
        </div>
      @endif

      @if($messageCount > 0)
        <div class="task-item">
          <div class="task-priority task-priority--medium" aria-hidden="true"></div>
          <span class="task-label">
            <i class="fas fa-envelope" style="margin-right:0.4rem;opacity:0.5;" aria-hidden="true"></i>
            New messages to respond
          </span>
          <span class="task-count" aria-label="{{ $messageCount }} messages">{{ $messageCount }}</span>
          <a href="/admin/messages" class="task-action" aria-label="Respond to new messages">Respond →</a>
        </div>
      @endif

      @if($membershipCount > 0)
        <div class="task-item">
          <div class="task-priority task-priority--medium" aria-hidden="true"></div>
          <span class="task-label">
            <i class="fas fa-users" style="margin-right:0.4rem;opacity:0.5;" aria-hidden="true"></i>
            Memberships awaiting payment
          </span>
          <span class="task-count" aria-label="{{ $membershipCount }} memberships">{{ $membershipCount }}</span>
          <a href="/admin/members/list?status=pending" class="task-action" aria-label="Review memberships awaiting payment">Review →</a>
        </div>
      @endif

      @if($orderCount === 0 && $messageCount === 0 && $membershipCount === 0)
        <div class="sys-notif sys-notif--info" role="status">
          <i class="fas fa-check-circle" aria-hidden="true"></i>
          <span>No pending tasks — you're all caught up.</span>
        </div>
      @endif

    </div><!-- /.admin-panel__body -->
  </div>

</section>


<!-- ══════════════════════════════════════════════
     6. SYSTEM ALERTS
════════════════════════════════════════════════ -->
<section class="content-grid" aria-label="System alerts">
  <div class="admin-panel">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-red);" aria-hidden="true"></span>
        System Alerts
      </h2>
    </div>
    <div style="padding:0.75rem 1.25rem 1.25rem;" id="sysAlerts">
      @if(!empty($systemAlerts))
        @foreach($systemAlerts as $alert)
          <div class="sys-notif sys-notif--{{ $alert['type'] }}" role="status">
            <i class="{{ $alert['icon'] }}" aria-hidden="true"></i>
            <span>{{ $alert['message'] }}</span>
          </div>
        @endforeach
      @else
        <div class="sys-notif sys-notif--info" role="status">
          <i class="fas fa-check-circle" aria-hidden="true"></i>
          <span>All systems operational</span>
        </div>
      @endif
    </div>
  </div>
</section>


<!-- ══════════════════════════════════════════════
     6. CHART DATA — injected for dashboard.js
════════════════════════════════════════════════ -->
<script id="dashboardData" type="application/json">
  {!! json_encode([
    'chartData' => $chartData ?? null,
    'stats'     => $stats     ?? null,
  ], JSON_HEX_TAG | JSON_HEX_AMP) !!}
</script>

@endsection
