@extends('layouts.admin')

@section('content')
@php
$admin = $admin ?? null;
@endphp

<div class="admin-page-header">
  <h2 class="admin-page-header__heading">My Profile</h2>
</div>

<div class="admin-form-card">
  <p style="margin-bottom:1rem;opacity:.8;">
    Role: <strong>{{ $admin?->roleLabel() }}</strong>
    &middot; Email: <strong>{{ $admin?->email }}</strong>
  </p>

  <form method="post" action="{{ url('/admin/profile') }}">
    @csrf

    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}">
      <label for="name" class="admin-label">Name</label>
      <input type="text" id="name" name="name" required
             value="{{ old('name', $admin->name ?? '') }}"
             class="admin-input">
      @error('name')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group">
      <label for="email" class="admin-label">Email</label>
      <input type="email" id="email" value="{{ $admin->email ?? '' }}" class="admin-input" disabled>
      <p class="form-group__hint" style="margin-top:.35rem;opacity:.7;font-size:.85rem;">
        Email changes require a Super Admin. Ask them to update your account under Admin Users.
      </p>
    </div>

    <hr style="margin:1.5rem 0;border:0;border-top:1px solid var(--border, #333);">

    <h3 style="margin-bottom:1rem;font-size:1rem;">Change password</h3>

    <div class="form-group{{ $errors->has('current_password') ? ' form-group--error' : '' }}">
      <label for="current_password" class="admin-label">Current password</label>
      <input type="password" id="current_password" name="current_password"
             class="admin-input" autocomplete="current-password">
      @error('current_password')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('password') ? ' form-group--error' : '' }}">
      <label for="password" class="admin-label">New password</label>
      <input type="password" id="password" name="password"
             class="admin-input" autocomplete="new-password">
      @error('password')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group">
      <label for="password_confirmation" class="admin-label">Confirm new password</label>
      <input type="password" id="password_confirmation" name="password_confirmation"
             class="admin-input" autocomplete="new-password">
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">Save profile</button>
  </form>
</div>

@endsection
