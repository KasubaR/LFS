@extends('layouts.admin')

@section('content')
@php
$list         = $orderList    ?? [];
$statusFilter = $filters['status'] ?? '';
$currentPage  = $currentPage  ?? 1;
$formatPrice  = $formatPrice  ?? fn ($v) => 'ZMW ' . number_format((float)$v, 2);
$breadcrumbs  = [
    ['label' => 'Admin',  'url' => '/admin/dashboard'],
    ['label' => 'Orders'],
];

$statusLabels = [
    'pending_payment' => 'Pending Payment',
    'paid'            => 'Paid',
    'processing'      => 'Processing',
    'ready'           => 'Ready',
    'collected'       => 'Collected',
    'cancelled'       => 'Cancelled',
    'payment_failed'  => 'Payment Failed',
];
$statusBadges = [
    'pending_payment' => 'orange',
    'paid'            => 'blue',
    'processing'      => 'blue',
    'ready'           => 'green',
    'collected'       => 'green',
    'cancelled'       => 'red',
    'payment_failed'  => 'red',
];
$allStatuses = \App\Enums\OrderStatus::ALL;
@endphp

<!-- Page header -->
<div class="admin-page-header">
  <h2 class="admin-page-header__heading">Orders</h2>
</div>

<!-- Stats row -->
<section class="stats-grid" aria-label="Order stats">
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-clock" aria-hidden="true"></i></div>
    <p class="stat-card__label">Pending Payment</p>
    <p class="stat-card__value">{{ (int)($statusCounts['pending_payment'] ?? 0) }}</p>
  </article>
  <article class="stat-card stat-card--blue">
    <div class="stat-card__icon"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
    <p class="stat-card__label">Paid</p>
    <p class="stat-card__value">{{ (int)($statusCounts['paid'] ?? 0) }}</p>
  </article>
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-bag-shopping" aria-hidden="true"></i></div>
    <p class="stat-card__label">Collected</p>
    <p class="stat-card__value">{{ (int)($statusCounts['collected'] ?? 0) }}</p>
  </article>
  <article class="stat-card stat-card--red">
    <div class="stat-card__icon"><i class="fas fa-circle-xmark" aria-hidden="true"></i></div>
    <p class="stat-card__label">Cancelled / Failed</p>
    <p class="stat-card__value">{{ (int)($statusCounts['cancelled'] ?? 0) + (int)($statusCounts['payment_failed'] ?? 0) }}</p>
  </article>
</section>

<!-- Toolbar: status filter -->
<div class="admin-toolbar">
  <form method="GET" action="{{ url('/admin/orders') }}" class="admin-toolbar__filters">
    <label for="orders-status-filter" class="admin-label">Status</label>
    <select id="orders-status-filter" name="status" class="admin-select"
            onchange="this.form.submit()">
      <option value="">All orders</option>
      @foreach($allStatuses as $s)
        <option value="{{ $s }}"
          {{ $statusFilter === $s ? 'selected' : '' }}>
          {{ $statusLabels[$s] ?? $s }}
          ({{ (int)($statusCounts[$s] ?? 0) }})
        </option>
      @endforeach
    </select>
    @if($statusFilter !== '')
      <a href="{{ url('/admin/orders') }}" class="admin-btn admin-btn--ghost admin-btn--sm">
        <i class="fas fa-xmark" aria-hidden="true"></i> Clear
      </a>
    @endif
  </form>
</div>

<!-- Orders table -->
@if(!empty($list))

  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Customer</th>
          <th>Total</th>
          <th>Status</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($list as $o)
        @php
            $badge = $statusBadges[$o['status']] ?? 'muted';
            $lbl   = $statusLabels[$o['status']] ?? $o['status'];
        @endphp
        <tr>
          <td>
            <a href="/admin/orders/{{ (int)$o['id'] }}">
              <strong>{{ $o['order_number'] }}</strong>
            </a>
          </td>
          <td>
            {{ $o['customer_name'] }}
            <div class="admin-table__sub">{{ $o['customer_email'] }}</div>
          </td>
          <td>{{ ($formatPrice)($o['total']) }}</td>
          <td>
            <span class="status-pill status-pill--{{ $badge }}">
              {{ $lbl }}
            </span>
          </td>
          <td>{{ date('d M Y', strtotime($o['created_at'])) }}</td>
          <td>
            <a href="/admin/orders/{{ (int)$o['id'] }}"
               class="admin-btn admin-btn--primary admin-btn--sm">
              View
            </a>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  @if(($pages ?? 1) > 1)
    <nav class="admin-toolbar" aria-label="Pagination" style="justify-content:center;margin-top:1rem;">
      @for($p = 1; $p <= $pages; $p++)
        @php
          $pHref = '/admin/orders?page=' . $p;
          if ($statusFilter !== '') $pHref .= '&status=' . urlencode($statusFilter);
        @endphp
        <a href="{{ url($pHref) }}"
           class="admin-btn admin-btn--sm {{ $p === $currentPage ? 'admin-btn--primary' : 'admin-btn--ghost' }}"
           aria-current="{{ $p === $currentPage ? 'page' : 'false' }}">
          {{ $p }}
        </a>
      @endfor
    </nav>
  @endif

@else

  <div class="admin-empty">
    <i class="fas fa-bag-shopping admin-empty__icon" aria-hidden="true"></i>
    <p class="admin-empty__title">No orders found</p>
    <p class="admin-empty__body">
      {{ $statusFilter !== ''
          ? 'No orders match the selected status filter.'
          : 'Orders will appear here once customers place them.' }}
    </p>
    @if($statusFilter !== '')
      <a href="{{ url('/admin/orders') }}" class="admin-btn admin-btn--ghost">
        <i class="fas fa-xmark" aria-hidden="true"></i> Clear filter
      </a>
    @endif
  </div>

@endif

@endsection
