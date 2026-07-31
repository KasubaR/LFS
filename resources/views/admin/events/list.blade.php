@extends('layouts.admin')

@section('content')
@php
$pageTitle   = $pageTitle   ?? 'Events';
$activePage  = 'events';
$breadcrumbs = $breadcrumbs ?? [
    ['label' => 'Admin', 'url' => '/admin'],
    ['label' => 'Events'],
];

$evList          = $events          ?? [];
$eventCategories = $eventCategories ?? [];
$filterCategory  = $filterCategory  ?? '';

$now           = new DateTime();
$upcomingCount = 0;
foreach ($evList as $ev) {
    if (!empty($ev['eventDate']) && new DateTime($ev['eventDate']) >= $now) {
        $upcomingCount++;
    }
}

if (! function_exists('formatEventDate')) {
    function formatEventDate(?string $d): string {
        if (!$d) return '—';
        return date('j M Y', strtotime($d));
    }
}
@endphp

@if(!empty($eventsError))
  <div class="gallery-error-banner" role="alert" style="margin-bottom:1rem;">
    <i class="fas fa-triangle-exclamation"></i>
    <span>{{ $eventsError }}</span>
  </div>
@endif

<!-- ══════════════════════════════════════════════
     STATS ROW
════════════════════════════════════════════════ -->
<section class="stats-grid" style="grid-template-columns:repeat(2,1fr); margin-bottom:1.5rem;" aria-label="Event stats">
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-calendar-days"></i></div>
    <p class="stat-card__label">Total Events</p>
    <p class="stat-card__value">{{ count($evList) }}</p>
  </article>
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-clock"></i></div>
    <p class="stat-card__label">Upcoming</p>
    <p class="stat-card__value">{{ $upcomingCount }}</p>
  </article>
</section>

<!-- ══════════════════════════════════════════════
     TOOLBAR
════════════════════════════════════════════════ -->
<div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">
  <form method="GET" action="/admin/events/list" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
    <select name="category"
            style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem;">
      <option value="">All Categories</option>
      @foreach($eventCategories as $c)
        <option value="{{ $c }}"
          {{ $filterCategory === $c ? 'selected' : '' }}>
          {{ $c }}
        </option>
      @endforeach
    </select>
    <button type="submit"
            style="padding:0.55rem 1rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.85rem; cursor:pointer;">
      Filter
    </button>
    <a href="/admin/events/list"
       style="padding:0.55rem 0.8rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); font-size:0.85rem; text-decoration:none;">
      <i class="fas fa-xmark"></i> Clear
    </a>
  </form>
  <a href="/admin/events/create"
     style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.6rem 1.1rem; background:var(--flag-green); color:#fff; border-radius:8px; font-family:var(--font-body); font-size:0.85rem; font-weight:600; text-decoration:none; white-space:nowrap;">
    <i class="fas fa-plus"></i> New Event
  </a>
</div>

<!-- ══════════════════════════════════════════════
     EVENTS TABLE
════════════════════════════════════════════════ -->
@if(!empty($evList))
  <div style="overflow-x:auto;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-mid); text-align:left;">
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Title</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Category</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Date</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Location</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Slug</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($evList as $ev)
          <tr style="border-bottom:1px solid var(--border-subtle);">
            <td style="padding:0.75rem;">
              <a href="/events/{{ $ev['slug'] ?? $ev['id'] }}"
                 target="_blank" rel="noopener"
                 style="color:var(--green-bright); text-decoration:none;">
                {{ $ev['title'] }}
              </a>
            </td>
            <td style="padding:0.75rem; color:var(--off-white);">{{ $ev['category'] ?? '—' }}</td>
            <td style="padding:0.75rem; color:var(--off-white);">{{ formatEventDate($ev['eventDate'] ?? null) }}</td>
            <td style="padding:0.75rem; color:var(--text-dim);">{{ $ev['location'] ?? '—' }}</td>
            <td style="padding:0.75rem; color:var(--text-dim); font-size:0.85rem;">{{ $ev['slug'] ?? '—' }}</td>
            <td style="padding:0.75rem;">
              <div class="upcoming-events__actions">
                <a href="/admin/events/{{ $ev['id'] }}/edit"
                   class="admin-btn admin-btn--primary admin-btn--sm">
                  <i class="fas fa-pen" aria-hidden="true"></i> Edit
                </a>
                <form method="POST"
                      action="/admin/events/{{ $ev['id'] }}/delete"
                      class="admin-inline-form"
                      onsubmit="return confirm('Delete: {{ addslashes($ev['title'] ?? 'this event') }}? This cannot be undone.')">
                  <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">
                  <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm">
                    <i class="fas fa-trash" aria-hidden="true"></i> Delete
                  </button>
                </form>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@else
  <div style="text-align:center; padding:4rem 2rem; color:var(--text-dim);">
    <i class="fas fa-calendar-xmark" style="font-size:3rem; margin-bottom:1rem; display:block; opacity:0.4;"></i>
    <p style="font-size:1.1rem; margin-bottom:0.5rem;">No events yet</p>
    <p style="font-size:0.85rem; margin-bottom:1.5rem;">Create your first event to show it on the public events page.</p>
    <a href="/admin/events/create"
       style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.65rem 1.25rem; background:var(--flag-green); color:#fff; border-radius:8px; text-decoration:none; font-weight:600;">
      <i class="fas fa-plus"></i> New Event
    </a>
  </div>
@endif

@endsection
