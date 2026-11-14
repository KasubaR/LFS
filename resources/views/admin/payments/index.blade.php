@extends('layouts.admin')

@section('content')
@php
$f = $filters;
$fmt = fn ($v, $cur = 'ZMW') => $cur.' '.number_format((float) $v, 2);
$totalCount = array_sum($counts);
$qs = fn (array $over) => http_build_query(array_filter(array_merge(request()->query(), ['page' => null], $over), fn ($v) => $v !== null && $v !== ''));
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">{{ $pageTitle }}</h2>
</div>

<section class="stats-grid" aria-label="Payment stats">
  @foreach($statuses as $key => [$label, $color])
    <a href="{{ url($selfUrl) }}?{{ $qs(['status' => $key]) }}" style="text-decoration:none;color:inherit;">
      <article class="stat-card stat-card--{{ $color === 'muted' ? 'blue' : $color }}">
        <p class="stat-card__label">{{ $label }}</p>
        <p class="stat-card__value">{{ (int) ($counts[$key] ?? 0) }}</p>
        <p class="admin-table__sub">{{ $fmt($sums[$key] ?? 0) }}</p>
      </article>
    </a>
  @endforeach
</section>

<div class="admin-toolbar" style="margin:1rem 0;">
  <form method="GET" action="{{ url($selfUrl) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
    <div>
      <label class="admin-label" for="pf-status">Status</label>
      <select id="pf-status" name="status" class="admin-select">
        <option value="">All ({{ $totalCount }})</option>
        @foreach($statuses as $key => [$label])
          <option value="{{ $key }}" {{ $f['status'] === $key ? 'selected' : '' }}>{{ $label }} ({{ (int) ($counts[$key] ?? 0) }})</option>
        @endforeach
      </select>
    </div>
    @if($extraFilter)
      <div>
        <label class="admin-label" for="pf-extra">{{ $extraFilter['label'] }}</label>
        <select id="pf-extra" name="{{ $extraFilter['name'] }}" class="admin-select">
          <option value="">All</option>
          @foreach($extraFilter['options'] as $val => $lbl)
            <option value="{{ $val }}" {{ $extraFilter['value'] === (string) $val ? 'selected' : '' }}>{{ $lbl }}</option>
          @endforeach
        </select>
      </div>
    @endif
    <div>
      <label class="admin-label" for="pf-from">From</label>
      <input id="pf-from" type="date" name="from" value="{{ $f['from'] }}" class="admin-input">
    </div>
    <div>
      <label class="admin-label" for="pf-to">To</label>
      <input id="pf-to" type="date" name="to" value="{{ $f['to'] }}" class="admin-input">
    </div>
    <div>
      <label class="admin-label" for="pf-q">Search</label>
      <input id="pf-q" type="search" name="q" value="{{ $f['q'] }}" placeholder="Name, email, phone, ref" class="admin-input">
    </div>
    <button type="submit" class="admin-btn admin-btn--primary">Filter</button>
    @if($f['status'] !== '' || $f['q'] !== '' || $f['from'] !== '' || $f['to'] !== '' || ($extraFilter && $extraFilter['value'] !== ''))
      <a href="{{ url($selfUrl) }}" class="admin-btn admin-btn--ghost">Clear</a>
    @endif
  </form>
</div>

@if($rows->isNotEmpty())
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>{{ $refLabel }}</th>
          <th>Customer</th>
          <th>Contact</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $r)
          @php [$label, $color] = $statuses[$r['status']] ?? [ucfirst(str_replace('_', ' ', (string) $r['status'])), 'muted']; @endphp
          <tr>
            <td>
              @if($r['link'])<a href="{{ $r['link'] }}" class="admin-table__link"><strong>{{ $r['ref'] }}</strong></a>@else<strong>{{ $r['ref'] }}</strong>@endif
              @if($r['detail'])<div class="admin-table__sub">{{ $r['detail'] }}</div>@endif
            </td>
            <td>{{ $r['who'] }}</td>
            <td>
              @if($r['phone'])<a href="tel:{{ $r['phone'] }}" class="admin-table__link">{{ $r['phone'] }}</a><br>@endif
              @if($r['email'])<span class="admin-table__sub">{{ $r['email'] }}</span>@endif
            </td>
            <td>{{ $fmt($r['amount'], $r['currency']) }}</td>
            <td>
              <span class="status-pill status-pill--{{ $color }}">{{ $label }}</span>
              @if($r['note'])<div class="admin-table__sub">{{ $r['note'] }}</div>@endif
            </td>
            <td>{{ $r['date'] ? $r['date']->format('d M Y H:i') : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <x-admin.pagination :paginator="$page" />
@else
  <div class="admin-empty"><p>No payments match these filters.</p></div>
@endif
@endsection
