@extends('layouts.admin')

@section('content')
@php
$money = fn ($v) => 'ZMW ' . number_format((float) $v, 2);
$sales = $sales ?? collect();
@endphp

<div class="admin-page-header admin-page-header--row">
  <div>
    <a href="{{ url('/admin/orders') }}" class="admin-page-header__back">
      <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to orders
    </a>
    <h2 class="admin-page-header__heading">Offline sales report</h2>
  </div>
  <a href="{{ url('/admin/orders/sales/create') }}" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> Record offline sale
  </a>
</div>

<form method="get" action="{{ url('/admin/orders/sales') }}" class="admin-toolbar__filters" style="margin-bottom:1rem;">
  <label for="rep-from" class="admin-label">From</label>
  <input id="rep-from" type="date" name="from" value="{{ $from }}" class="admin-input">
  <label for="rep-to" class="admin-label">To</label>
  <input id="rep-to" type="date" name="to" value="{{ $to }}" class="admin-input">
  <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Show</button>
  <a href="{{ url('/admin/orders/sales/export?from='.$from.'&to='.$to) }}" class="admin-btn admin-btn--ghost admin-btn--sm">
    <i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV
  </a>
</form>
<p class="text-muted" style="margin-top:-.5rem;">Days are in {{ $timezone }} time.</p>

<section class="stats-grid" aria-label="Offline sales totals">
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-receipt" aria-hidden="true"></i></div>
    <p class="stat-card__label">Sales</p>
    <p class="stat-card__value">{{ $totals['count'] }}</p>
  </article>
  <article class="stat-card stat-card--gold">
    <div class="stat-card__icon"><i class="fas fa-coins" aria-hidden="true"></i></div>
    <p class="stat-card__label">Total taken</p>
    <p class="stat-card__value" style="font-size:1.4rem;">{{ $money($totals['total']) }}</p>
  </article>
  @if(($totals['discount'] ?? 0) > 0)
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-tags" aria-hidden="true"></i></div>
    <p class="stat-card__label">Discounts given</p>
    <p class="stat-card__value" style="font-size:1.4rem;">{{ $money($totals['discount']) }}</p>
  </article>
  @endif
  @foreach($totals['byMethod'] as $row)
  <article class="stat-card stat-card--blue">
    <div class="stat-card__icon"><i class="fas fa-wallet" aria-hidden="true"></i></div>
    <p class="stat-card__label">{{ $row['label'] }}</p>
    <p class="stat-card__value" style="font-size:1.4rem;">{{ $money($row['total']) }}</p>
    <div class="stat-card__meta"><span style="font-size:.68rem;color:var(--text-dim);">{{ $row['count'] }} sale(s)</span></div>
  </article>
  @endforeach
  @if($totals['voidedCount'] > 0)
  <article class="stat-card stat-card--red">
    <div class="stat-card__icon"><i class="fas fa-ban" aria-hidden="true"></i></div>
    <p class="stat-card__label">Voided (not counted)</p>
    <p class="stat-card__value" style="font-size:1.4rem;">{{ $money($totals['voidedTotal']) }}</p>
    <div class="stat-card__meta"><span style="font-size:.68rem;color:var(--text-dim);">{{ $totals['voidedCount'] }} sale(s)</span></div>
  </article>
  @endif
</section>

@if($sales->isNotEmpty())
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Receipt</th>
          <th>Time</th>
          <th>Customer</th>
          <th>Paid by</th>
          <th>Total</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($sales as $s)
        <tr>
          <td><a href="{{ url('/admin/orders/'.$s->id) }}"><strong>{{ $s->order_number }}</strong></a></td>
          <td>{{ $s->created_at->copy()->timezone($timezone)->format('d M, H:i') }}</td>
          <td>{{ $s->customer_name }}<div class="admin-table__sub">{{ $s->customer_phone }}</div></td>
          <td>{{ $paymentLabels[$s->payment_method] ?? $s->payment_method }}</td>
          <td>{{ $money($s->total) }}</td>
          <td>
            @if($s->status === \App\Enums\OrderStatus::Cancelled)
              <span class="status-pill status-pill--red">Voided</span>
            @else
              <span class="status-pill status-pill--green">Completed</span>
            @endif
          </td>
          <td><a href="{{ url('/admin/orders/'.$s->id.'/receipt') }}" class="admin-btn admin-btn--ghost admin-btn--sm">Receipt</a></td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@else
  <div class="admin-empty">
    <i class="fas fa-receipt admin-empty__icon" aria-hidden="true"></i>
    <p class="admin-empty__title">No offline sales in this period</p>
  </div>
@endif
@endsection
