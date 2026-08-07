@extends('layouts.admin')

@section('content')
@php
$pageTitle   = 'Members';
$activePage  = 'members';
$breadcrumbs = [];
$members     = $members ?? [];

$totalCount     = count($members);
$activeCount    = count(array_filter($members, fn($m) => ($m['status'] ?? '') === 'active'));
$pendingCount   = count(array_filter($members, fn($m) => ($m['status'] ?? '') === 'pending'));
$suspendedCount = count(array_filter($members, fn($m) => ($m['status'] ?? '') === 'suspended'));
$inactiveCount  = count(array_filter($members, fn($m) => ($m['status'] ?? '') === 'inactive'));
@endphp

<!-- ══════════════════════════════════════════════
     STATS
════════════════════════════════════════════════ -->
<section class="stats-grid" style="grid-template-columns:repeat(5,1fr); margin-bottom:1.5rem;" aria-label="Member stats">
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-users"></i></div>
    <p class="stat-card__label">Total</p>
    <p class="stat-card__value">{{ number_format($totalCount) }}</p>
  </article>
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-user-check"></i></div>
    <p class="stat-card__label">Active</p>
    <p class="stat-card__value">{{ number_format($activeCount) }}</p>
  </article>
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-user-clock"></i></div>
    <p class="stat-card__label">Pending</p>
    <p class="stat-card__value">{{ number_format($pendingCount) }}</p>
  </article>
  <article class="stat-card stat-card--blue">
    <div class="stat-card__icon"><i class="fas fa-user-lock"></i></div>
    <p class="stat-card__label">Suspended</p>
    <p class="stat-card__value">{{ number_format($suspendedCount) }}</p>
  </article>
  <article class="stat-card stat-card--red">
    <div class="stat-card__icon"><i class="fas fa-user-xmark"></i></div>
    <p class="stat-card__label">Inactive</p>
    <p class="stat-card__value">{{ number_format($inactiveCount) }}</p>
  </article>
</section>

<div style="text-align:left; margin-bottom:1.5rem;">
  <a href="{{ url('/admin/members/list') }}" class="admin-btn admin-btn--primary">
    View all members <i class="fas fa-arrow-right" aria-hidden="true"></i>
  </a>
</div>

<!-- ══════════════════════════════════════════════
     MEMBERSHIP ANALYTICS
════════════════════════════════════════════════ -->
<section class="analytics-grid" aria-label="Membership analytics">

  <!-- Active vs Expired -->
  <div class="admin-panel" aria-label="Active vs expired members">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-green);" aria-hidden="true"></span>
        Active vs Expired
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <canvas id="chartStatusBreakdown" aria-label="Active vs expired members chart"></canvas>
      </div>
    </div>
  </div>

  <!-- Revenue by month -->
  <div class="admin-panel" aria-label="Revenue by month">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--gold);" aria-hidden="true"></span>
        Revenue by Month
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <canvas id="chartRevenue" aria-label="Revenue by month chart"></canvas>
      </div>
    </div>
  </div>

  <!-- Membership growth -->
  <div class="admin-panel" aria-label="Membership growth">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-green);" aria-hidden="true"></span>
        Membership Growth
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <canvas id="chartGrowth" aria-label="Membership growth chart"></canvas>
      </div>
    </div>
  </div>

  <!-- New registrations -->
  <div class="admin-panel" aria-label="New registrations">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-orange);" aria-hidden="true"></span>
        New Registrations
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <canvas id="chartRegistrations" aria-label="New registrations chart"></canvas>
      </div>
    </div>
  </div>

  <!-- Renewals -->
  <div class="admin-panel" aria-label="Renewals">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:#3b82f6;" aria-hidden="true"></span>
        Renewals
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <canvas id="chartRenewals" aria-label="Renewals chart"></canvas>
      </div>
    </div>
  </div>

  <!-- Members per satellite -->
  <div class="admin-panel" aria-label="Members per satellite">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-green);" aria-hidden="true"></span>
        Members per Satellite
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <canvas id="chartSatellites" aria-label="Members per satellite chart"></canvas>
      </div>
    </div>
  </div>

  <!-- Gender distribution -->
  <div class="admin-panel" aria-label="Gender distribution">
    <div class="admin-panel__header">
      <h2 class="admin-panel__title">
        <span class="admin-panel__title-dot" style="background:var(--flag-red);" aria-hidden="true"></span>
        Gender Distribution
      </h2>
    </div>
    <div class="admin-panel__body">
      <div class="chart-wrapper" style="height:260px;position:relative;">
        <canvas id="chartGender" aria-label="Gender distribution chart"></canvas>
      </div>
    </div>
  </div>

</section>

<script id="memberChartsData" type="application/json">
  {!! json_encode(['chartData' => $chartData ?? null], JSON_HEX_TAG | JSON_HEX_AMP) !!}
</script>

@endsection
