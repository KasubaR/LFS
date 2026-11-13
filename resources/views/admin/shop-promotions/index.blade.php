@extends('layouts.admin')

@section('content')
@php
$promotions    = $promotions ?? [];
$categoryNames = $categoryNames ?? [];
$csrfToken     = $csrfToken ?? '';
$statusLabel = fn (string $s) => match ($s) {
    'active' => 'Active',
    'upcoming' => 'Upcoming',
    'expired' => 'Expired',
    default => 'Disabled',
};
$statusColor = fn (string $s) => match ($s) {
    'active'   => ['bg' => 'rgba(74,124,89,0.2)',    'color' => 'var(--green-bright)', 'border' => 'rgba(74,124,89,0.4)'],
    'upcoming' => ['bg' => 'rgba(224,123,57,0.15)',  'color' => 'var(--flag-orange)',  'border' => 'rgba(224,123,57,0.3)'],
    'expired'  => ['bg' => 'rgba(192,57,43,0.15)',   'color' => '#e88',                'border' => 'rgba(192,57,43,0.3)'],
    default    => ['bg' => 'rgba(255,255,255,0.06)', 'color' => 'var(--text-dim)',     'border' => 'var(--border-mid)'],
};
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">Shop promotions</h2>
  <a href="/admin/shop-promotions/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> Add shop promotion
  </a>
</div>

<p class="admin-hint">
  Promo codes and automatic category sales for the online shop and in-person sales.
  Membership discounts are managed separately under Promotions.
</p>

@if(empty($promotions))
  <p class="admin-empty">No shop promotions yet.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Code</th>
          <th>Discount</th>
          <th>Category</th>
          <th>Period</th>
          <th>Members only</th>
          <th>Used</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($promotions as $p)
        @php($color = $statusColor($p['status']))
        <tr>
          <td>{{ $p['name'] }}</td>
          <td>{{ $p['code'] ?? 'Automatic sale' }}</td>
          <td>{{ $p['discountType'] === 'percentage' ? rtrim(rtrim(number_format($p['discountValue'], 2), '0'), '.') . '%' : 'K' . number_format($p['discountValue'], 2) }}</td>
          <td>{{ $p['category'] ? ($categoryNames[$p['category']] ?? $p['category']) : 'All products' }}</td>
          <td>{{ $p['startsAt'] }} &ndash; {{ $p['endsAt'] }}</td>
          <td>{{ $p['membersOnly'] ? 'Yes' : 'No' }}</td>
          <td>
            @if($p['isAutomatic'])
              &mdash;
            @else
              {{ $p['orders'] }} order{{ $p['orders'] === 1 ? '' : 's' }} &middot; K{{ number_format($p['discounted'], 2) }} off
            @endif
          </td>
          <td>
            <span style="display:inline-block; padding:0.2rem 0.6rem; background:{{ $color['bg'] }}; color:{{ $color['color'] }}; border:1px solid {{ $color['border'] }}; border-radius:20px; font-size:0.75rem; font-weight:600; white-space:nowrap;">
              {{ $statusLabel($p['status']) }}
            </span>
          </td>
          <td class="cell-actions">
            <a href="/admin/shop-promotions/{{ (int)$p['id'] }}/edit" class="admin-btn admin-btn--primary admin-btn--sm">Edit</a>
            <form method="post" action="/admin/shop-promotions/{{ (int)$p['id'] }}/delete" onsubmit="return confirm('Delete this promotion? Past orders keep their discount.');">
              <input type="hidden" name="_csrf" value="{{ $csrfToken }}">
              <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm">Delete</button>
            </form>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

@endsection
