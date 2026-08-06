@extends('layouts.admin')

@section('content')
@php
$pageTitle    = 'Members';
$activePage   = 'members';
$breadcrumbs  = $breadcrumbs ?? [];
$members      = $members     ?? [];
$filterStatus = $filterStatus ?? '';
@endphp

<!-- ══════════════════════════════════════════════
     TOOLBAR
════════════════════════════════════════════════ -->
<div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">
  <form method="GET" action="{{ url('/admin/members/list') }}"
        style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">

    <select name="status"
            style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem;">
      <option value="">All Members</option>
      <option value="active"    {{ $filterStatus === 'active'    ? 'selected' : '' }}>Active</option>
      <option value="pending"   {{ $filterStatus === 'pending'   ? 'selected' : '' }}>Pending</option>
      <option value="suspended" {{ $filterStatus === 'suspended' ? 'selected' : '' }}>Suspended</option>
      <option value="inactive"  {{ $filterStatus === 'inactive'  ? 'selected' : '' }}>Inactive</option>
    </select>

    <input type="search" name="search"
           placeholder="Search members…"
           style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem; min-width:180px;" />

    <button type="submit"
            style="padding:0.55rem 1rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.85rem; cursor:pointer;">
      Filter
    </button>
    <a href="{{ url('/admin/members/list') }}"
       style="padding:0.55rem 0.8rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); font-size:0.85rem; text-decoration:none;">
      <i class="fas fa-xmark"></i> Clear
    </a>
  </form>

  @if(empty($adminIsSatelliteAdmin) && !empty($adminCanWrite['members']))
  <a href="{{ url('/admin/members/import') }}" class="admin-btn admin-btn--primary">
    <i class="fas fa-file-import" aria-hidden="true"></i> Import Members
  </a>
  @endif
</div>

<!-- ══════════════════════════════════════════════
     MEMBERS TABLE / PLACEHOLDER
════════════════════════════════════════════════ -->
@if(!empty($members))
  <div style="overflow-x:auto;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-mid); text-align:left;">
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Name</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Email</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Phone</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Satellite</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Status</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Joined</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($members as $m)
          @php
            $statusColor = match ($m['status'] ?? '') {
              'active'    => ['bg' => 'rgba(74,124,89,0.2)',   'color' => 'var(--green-bright)', 'border' => 'rgba(74,124,89,0.4)'],
              'pending'   => ['bg' => 'rgba(224,123,57,0.15)', 'color' => 'var(--flag-orange)',  'border' => 'rgba(224,123,57,0.3)'],
              'suspended' => ['bg' => 'rgba(192,57,43,0.2)',   'color' => '#e88',                'border' => 'rgba(192,57,43,0.4)'],
              'inactive'  => ['bg' => 'rgba(192,57,43,0.15)',  'color' => '#e88',                'border' => 'rgba(192,57,43,0.3)'],
              default     => ['bg' => 'rgba(255,255,255,0.06)', 'color' => 'var(--text-dim)',    'border' => 'var(--border-mid)'],
            };
          @endphp
          <tr style="border-bottom:1px solid var(--border-subtle);">
            <td style="padding:0.75rem;">
              <span style="color:var(--off-white); font-size:0.9rem; font-weight:500;">
                {{ ($m['firstName'] ?? '') . ' ' . ($m['lastName'] ?? '') }}
              </span>
            </td>
            <td style="padding:0.75rem; color:var(--text-dim); font-size:0.88rem;">{{ $m['email'] ?? '—' }}</td>
            <td style="padding:0.75rem; color:var(--text-dim); font-size:0.88rem;">{{ $m['phone'] ?? '—' }}</td>
            <td style="padding:0.75rem; color:var(--off-white); font-size:0.88rem;">{{ $m['satellite'] ?? '—' }}</td>
            <td style="padding:0.75rem;">
              <span style="display:inline-block; padding:0.2rem 0.6rem; background:{{ $statusColor['bg'] }}; color:{{ $statusColor['color'] }}; border:1px solid {{ $statusColor['border'] }}; border-radius:20px; font-size:0.75rem; font-weight:600; white-space:nowrap; text-transform:capitalize;">
                {{ $m['status'] ?? 'unknown' }}
              </span>
            </td>
            <td style="padding:0.75rem; color:var(--text-dim); font-size:0.88rem;">
              {{ !empty($m['createdAt']) ? date('j M Y', strtotime($m['createdAt'])) : '—' }}
            </td>
            <td style="padding:0.75rem;">
              <div class="upcoming-events__actions">
                <a href="/admin/members/{{ $m['id'] }}"
                   class="admin-btn admin-btn--ghost admin-btn--sm">
                  View
                </a>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@else
  <div style="text-align:center; padding:5rem 2rem; color:var(--text-dim);">
    <i class="fas fa-users" style="font-size:3.5rem; margin-bottom:1.25rem; display:block; opacity:0.25;"></i>
    <p style="font-size:1.1rem; font-weight:600; color:var(--off-white); margin-bottom:0.5rem;">No members yet</p>
    <p style="font-size:0.88rem; max-width:36ch; margin:0 auto; line-height:1.7;">
      Member records will appear here once the membership system is connected.
    </p>
  </div>
@endif

@endsection
