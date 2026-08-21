@extends('layouts.admin')

@section('content')
@php
$users = $users ?? collect();
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">Admin Users</h2>
  <a href="/admin/users/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> Add Admin
  </a>
</div>

@if($users->isEmpty())
  <p class="admin-empty">No admin users yet.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Satellites</th>
          <th>Status</th>
          <th>Last login</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($users as $user)
        <tr>
          <td>{{ $user->name }}</td>
          <td>{{ $user->email }}</td>
          <td>{{ $user->roleLabel() }}</td>
          <td>
            @if($user->isSatelliteAdministrator())
              {{ $user->satellites->pluck('name')->join(', ') ?: '—' }}
            @else
              —
            @endif
          </td>
          <td>
            @if($user->is_active)
              <span class="status-pill status-pill--green">Active</span>
            @else
              <span class="status-pill status-pill--muted">Inactive</span>
            @endif
          </td>
          <td>{{ $user->last_login_at?->format('j M Y H:i') ?? 'Never' }}</td>
          <td class="cell-actions">
            <a href="/admin/users/{{ $user->id }}/edit" class="admin-btn admin-btn--primary admin-btn--sm">Edit</a>
            @if($user->requiresTotp() && $user->hasTotpEnabled())
              <form method="post" action="/admin/users/{{ $user->id }}/reset-totp" onsubmit="return confirm('Reset two-factor authentication for {{ addslashes($user->name) }}? They will need to set it up again on next login.');">
                @csrf
                <button type="submit" class="admin-btn admin-btn--sm">Reset 2FA</button>
              </form>
            @endif
            @if($user->is_active)
              <form method="post" action="/admin/users/{{ $user->id }}/deactivate" onsubmit="return confirm('Deactivate this admin user?');">
                @csrf
                <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm">Deactivate</button>
              </form>
            @else
              <form method="post" action="/admin/users/{{ $user->id }}/activate">
                @csrf
                <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Activate</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

@endsection
