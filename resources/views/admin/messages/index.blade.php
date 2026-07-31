@extends('layouts.admin')

@section('content')
@php
$messages     = $messages     ?? [];
$statusCounts = $statusCounts ?? ['New' => 0, 'Read' => 0, 'Responded' => 0];
$breadcrumbs  = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Messages'],
];
$csrfToken    = $csrfToken ?? '';
@endphp

<div class="admin-page-header message-list__header">
  <h2 class="message-list__title">Contact Messages</h2>
</div>

<!-- Status summary -->
<div class="stats-grid message-list__stats" aria-label="Message counts">
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-envelope"></i></div>
    <p class="stat-card__label">New</p>
    <p class="stat-card__value">{{ (int)($statusCounts['New'] ?? 0) }}</p>
  </article>
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-envelope-open"></i></div>
    <p class="stat-card__label">Read</p>
    <p class="stat-card__value">{{ (int)($statusCounts['Read'] ?? 0) }}</p>
  </article>
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-reply"></i></div>
    <p class="stat-card__label">Responded</p>
    <p class="stat-card__value">{{ (int)($statusCounts['Responded'] ?? 0) }}</p>
  </article>
</div>

@if(empty($messages))
  <p class="admin-empty message-list__empty">No contact messages yet.</p>
@else
  <div class="admin-table-wrap message-list__table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>From</th>
          <th>Email</th>
          <th>Subject</th>
          <th>Status</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($messages as $m)
        <tr>
          <td>{{ $m['name'] ?? '—' }}</td>
          <td><a href="mailto:{{ $m['email'] ?? '' }}" class="admin-table__link">{{ $m['email'] ?? '—' }}</a></td>
          <td>{{ $m['subject'] ?? '—' }}</td>
          <td>
            <span class="message-list__status-pill">
              {{ $m['status'] ?? '—' }}
            </span>
          </td>
          <td>{{ $m['created_at'] ?? '—' }}</td>
          <td class="cell-actions">
            <a href="/admin/messages/{{ urlencode((string)($m['id'] ?? '')) }}" class="admin-btn admin-btn--primary admin-btn--sm">View</a>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

@endsection
