@extends('layouts.admin')

@section('content')
@php
$money = fn ($n): string => 'K'.number_format((float) $n, 2);
$badge = function (string $status): array {
    return match ($status) {
        'paid' => ['Paid', '#16a34a'],
        'pending_payment' => ['Awaiting payment', '#d97706'],
        'payment_review_required' => ['Payment review', '#dc2626'],
        'cancelled' => ['Cancelled / expired', '#6b7280'],
        'failed' => ['Failed', '#6b7280'],
        default => [$status, '#6b7280'],
    };
};
$reasonLabel = fn (?string $r): string => match ($r) {
    'amount_mismatch' => 'Amount paid did not match the order total',
    'capacity_unavailable' => 'Paid after the reservation expired and the tickets were sold',
    'discount_unavailable' => 'Paid late and the member discount was taken by a newer order',
    'event_cancelled' => 'Paid for an event that has since been cancelled',
    'payment_after_failure' => 'Payment arrived on an order already marked failed',
    default => $r ?: 'Needs review',
};
$th = 'padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);';
@endphp

<div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">
  <a href="/admin/events/{{ $event->id }}/attendance"
     style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.6rem 1.1rem; background:var(--flag-green); color:#fff; border-radius:8px; font-size:0.85rem; font-weight:600; text-decoration:none;">
    <i class="fas fa-clipboard-user" aria-hidden="true"></i> Entrance attendance
  </a>
  <a href="/admin/events/{{ $event->id }}/tickets/export"
     style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.6rem 1.1rem; background:var(--black-soft); border:1px solid var(--border-mid); color:var(--off-white); border-radius:8px; font-size:0.85rem; text-decoration:none;">
    <i class="fas fa-file-csv" aria-hidden="true"></i> Export attendees (CSV)
  </a>
  <a href="/events/{{ $event->slug }}" target="_blank" rel="noopener"
     style="color:var(--green-bright); font-size:0.85rem; text-decoration:none;">View public page</a>
</div>

<!-- ── Stats ── -->
<section class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); margin-bottom:1.5rem;" aria-label="Ticket stats">
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-ticket"></i></div>
    <p class="stat-card__label">Tickets sold</p>
    <p class="stat-card__value">{{ $stats['ticketsSold'] }}@if($stats['capacity'] !== null)<small style="font-size:0.5em; opacity:0.6;"> / {{ $stats['capacity'] }}</small>@endif</p>
  </article>
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-money-bill-wave"></i></div>
    <p class="stat-card__label">Collected</p>
    <p class="stat-card__value" style="font-size:1.4rem;">{{ $money($stats['revenue']) }}</p>
  </article>
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-user-check"></i></div>
    <p class="stat-card__label">Present</p>
    <p class="stat-card__value">{{ $stats['present'] }}<small style="font-size:0.5em; opacity:0.6;"> / {{ $stats['ticketsSold'] }}</small></p>
  </article>
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-hourglass-half"></i></div>
    <p class="stat-card__label">Remaining</p>
    <p class="stat-card__value">{{ $stats['remaining'] ?? '∞' }}</p>
  </article>
</section>

<p style="color:var(--text-dim); font-size:0.85rem; margin-bottom:1.5rem;">
  {{ $stats['buyers'] }} paying buyer(s) ·
  {{ $stats['memberTickets'] }} member ticket(s), {{ $stats['standardTickets'] }} standard ·
  {{ $stats['pendingOrders'] }} order(s) awaiting payment holding {{ $stats['pendingTickets'] }} ticket(s)
</p>

<!-- ── Payment review ── -->
@if($reviewOrders->isNotEmpty())
<section style="border:1px solid #dc2626; background:rgba(220,38,38,0.08); border-radius:10px; padding:1rem 1.1rem; margin-bottom:1.5rem;" aria-label="Orders needing review">
  <h2 style="font-size:1rem; margin:0 0 0.25rem; color:#fca5a5;">
    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
    {{ $reviewOrders->count() }} order(s) need review: money received, tickets not confirmed
  </h2>
  <p style="font-size:0.8rem; color:var(--text-dim); margin:0 0 0.75rem;">
    These buyers have paid but no ticket is valid and nobody can be admitted on them. Fulfil or refund each one (refunds are made in Lenco).
  </p>
  <div style="overflow-x:auto;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="text-align:left; border-bottom:1px solid var(--border-mid);">
          <th style="{{ $th }}">Order</th><th style="{{ $th }}">Buyer</th><th style="{{ $th }}">Tickets</th>
          <th style="{{ $th }}">Paid</th><th style="{{ $th }}">Why</th><th style="{{ $th }}">Waiting</th>
        </tr>
      </thead>
      <tbody>
        @foreach($reviewOrders as $r)
        <tr style="border-bottom:1px solid var(--border-subtle);">
          <td style="padding:0.6rem 0.75rem; font-family:monospace;">{{ $r->order_number }}</td>
          <td style="padding:0.6rem 0.75rem;">{{ $r->buyer_name }}<br><small style="color:var(--text-dim);">{{ $r->buyer_email }}</small></td>
          <td style="padding:0.6rem 0.75rem;">{{ $r->quantity }}</td>
          <td style="padding:0.6rem 0.75rem;">{{ $money($r->total) }}</td>
          <td style="padding:0.6rem 0.75rem; font-size:0.85rem;">{{ $reasonLabel($r->review_reason) }}</td>
          <td style="padding:0.6rem 0.75rem; color:var(--text-dim); font-size:0.85rem;">{{ $r->updated_at?->diffForHumans(null, true) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</section>
@endif

<!-- ── Filter ── -->
<form method="GET" action="/admin/events/{{ $event->id }}/tickets" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
  <select name="status" style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-size:0.85rem;">
    @foreach($statusFilters as $val => $label)
      <option value="{{ $val }}" {{ $filterStatus === $val ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
  </select>
  <input type="search" name="q" value="{{ $filterQuery }}" placeholder="Name, email, order number or reference" class="admin-input" style="min-width:260px;">
  <button type="submit" style="padding:0.55rem 1rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-size:0.85rem; cursor:pointer;">Filter</button>
  <a href="/admin/events/{{ $event->id }}/tickets" style="padding:0.55rem 0.8rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); font-size:0.85rem; text-decoration:none;"><i class="fas fa-xmark"></i> Clear</a>
</form>

<!-- ── Orders and attendees ── -->
@if($orders->count())
<div style="overflow-x:auto;">
  <table class="admin-table" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr style="border-bottom:1px solid var(--border-mid); text-align:left;">
        <th style="{{ $th }}">Order</th>
        <th style="{{ $th }}">Buyer</th>
        <th style="{{ $th }}">Attendees</th>
        <th style="{{ $th }}">Total</th>
        <th style="{{ $th }}">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach($orders as $o)
        @php [$label, $color] = $badge($o->status); @endphp
        <tr style="border-bottom:1px solid var(--border-subtle); vertical-align:top;">
          <td style="padding:0.75rem;">
            <span style="font-family:monospace;">{{ $o->order_number }}</span><br>
            <small style="color:var(--text-dim);">{{ $o->created_at?->format('j M, H:i') }}</small>
          </td>
          <td style="padding:0.75rem;">
            {{ $o->buyer_name }}<br>
            <small style="color:var(--text-dim);">{{ $o->buyer_email }}</small><br>
            <small style="color:var(--text-dim);">{{ $o->buyer_phone ?: '—' }}@if($o->payer_phone && $o->payer_phone !== $o->buyer_phone) · pays from {{ $o->payer_phone }}@endif</small>
          </td>
          <td style="padding:0.75rem;">
            @foreach($o->tickets as $t)
              <div style="margin-bottom:0.25rem;">
                @if($o->status === 'paid')
                  <i class="fas {{ $t->checked_in_at ? 'fa-circle-check' : 'fa-circle' }}" style="color:{{ $t->checked_in_at ? '#16a34a' : '#6b7280' }};" aria-hidden="true"
                     title="{{ $t->checked_in_at ? 'Present at '.$t->checked_in_at->format('H:i') : 'Not yet present' }}"></i>
                @endif
                {{ $t->attendee_name }}
                @if($t->is_member_discount)<span style="color:#16a34a; font-size:0.75rem;"> · member {{ $t->membership_number }}</span>@endif
                <small style="color:var(--text-dim); font-family:monospace;"> {{ $t->ticket_reference }}</small>
                @if($t->attendee_email)<small style="color:var(--text-dim);"> · {{ $t->attendee_email }}</small>@endif
              </div>
            @endforeach
          </td>
          <td style="padding:0.75rem; white-space:nowrap;">{{ $money($o->total) }}<br><small style="color:var(--text-dim);">{{ $o->quantity }} ticket(s)</small></td>
          <td style="padding:0.75rem;">
            <span style="display:inline-block; padding:0.15rem 0.6rem; border-radius:999px; font-size:0.75rem; font-weight:600; background:{{ $color }}22; color:{{ $color }};">{{ $label }}</span>
            @if($o->status === 'payment_review_required')<br><small style="color:var(--text-dim);">{{ $reasonLabel($o->review_reason) }}</small>@endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
<div style="margin-top:1rem;">{{ $orders->links() }}</div>
@else
<div style="text-align:center; padding:3rem 2rem; color:var(--text-dim);">
  <i class="fas fa-ticket" style="font-size:2.5rem; margin-bottom:1rem; display:block; opacity:0.4;"></i>
  <p>No orders match this filter.</p>
</div>
@endif
@endsection
