@extends('layouts.admin')

@section('content')
@php
$announcements = $announcements ?? [];
$breadcrumbs   = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Announcements'],
];
$csrfToken     = $csrfToken ?? '';
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">Announcements</h2>
  <a href="/admin/announcements/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> Add Announcement
  </a>
</div>

@if(empty($announcements))
  <p class="admin-empty">No announcements yet.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Status</th>
          <th>Published</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($announcements as $a)
        <tr>
          <td class="cell-truncate">{{ mb_substr($a['title'] ?? '', 0, 80) }}{{ mb_strlen($a['title'] ?? '') > 80 ? '…' : '' }}</td>
          <td>
            @if($a['isActive'] ?? false)
              <span class="status-pill status-pill--green">Active</span>
            @else
              <span class="status-pill status-pill--muted">Inactive</span>
            @endif
          </td>
          <td>{{ $a['publishedAt'] ?? '—' }}</td>
          <td class="cell-actions">
            <a href="/admin/announcements/{{ $a['id'] }}/edit" class="admin-btn admin-btn--primary admin-btn--sm">Edit</a>
            <form method="post" action="/admin/announcements/{{ $a['id'] }}/delete" onsubmit="return confirm('Delete this announcement?');">
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
