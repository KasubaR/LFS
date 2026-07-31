@extends('layouts.admin')

@section('content')
@php
$admin = $admin ?? null;
$isEdit = $admin !== null;
$roles = $roles ?? [];
$satellites = $satellites ?? collect();
$selectedSatelliteIds = $selectedSatelliteIds ?? [];
$formAction = $isEdit ? '/admin/users/'.$admin->id.'/edit' : '/admin/users/create';
$satelliteRole = $satelliteRole ?? 'satellite_administrator';
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/users') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Admin Users
  </a>
  <h2 class="admin-page-header__heading">{{ $isEdit ? 'Edit Admin User' : 'Add Admin User' }}</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ $formAction }}" id="admin-user-form">
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

    <div class="form-group{{ $errors->has('email') ? ' form-group--error' : '' }}">
      <label for="email" class="admin-label">Email</label>
      <input type="email" id="email" name="email" required
             value="{{ old('email', $admin->email ?? '') }}"
             class="admin-input" autocomplete="username">
      @error('email')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('role') ? ' form-group--error' : '' }}">
      <label for="role" class="admin-label">Role</label>
      <select id="role" name="role" required class="admin-input" data-satellite-role="{{ $satelliteRole }}">
        @foreach($roles as $value => $label)
          <option value="{{ $value }}" @selected(old('role', $admin->role ?? '') === $value)>{{ $label }}</option>
        @endforeach
      </select>
      @error('role')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group" id="satellite-fields" hidden>
      <label class="admin-label">Assigned satellites</label>
      <div class="admin-checkbox-list">
        @foreach($satellites as $satellite)
          <label class="admin-label" style="display:flex;align-items:center;gap:0.5rem;font-weight:400;">
            <input type="checkbox" name="satellite_ids[]" value="{{ $satellite->id }}"
              @checked(in_array((int) $satellite->id, array_map('intval', (array) old('satellite_ids', $selectedSatelliteIds)), true))>
            {{ $satellite->name }}@if($satellite->town) ({{ $satellite->town }})@endif
          </label>
        @endforeach
      </div>
      @error('satellite_ids')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('password') ? ' form-group--error' : '' }}">
      <label for="password" class="admin-label">
        Password @if($isEdit)<span style="font-weight:400;opacity:.7;">(leave blank to keep current)</span>@endif
      </label>
      <input type="password" id="password" name="password"
             class="admin-input" autocomplete="new-password"
             @if(!$isEdit) required @endif>
      @error('password')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('password_confirmation') ? ' form-group--error' : '' }}">
      <label for="password_confirmation" class="admin-label">Confirm password</label>
      <input type="password" id="password_confirmation" name="password_confirmation"
             class="admin-input" autocomplete="new-password"
             @if(!$isEdit) required @endif>
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">{{ $isEdit ? 'Save changes' : 'Create Admin' }}</button>
    <a href="{{ url('/admin/users') }}" class="admin-btn admin-btn--ghost">Cancel</a>
  </form>
</div>

<script>
(function () {
  var roleSelect = document.getElementById('role');
  var satelliteFields = document.getElementById('satellite-fields');
  if (!roleSelect || !satelliteFields) return;

  function syncSatelliteVisibility() {
    var needed = roleSelect.value === roleSelect.getAttribute('data-satellite-role');
    satelliteFields.hidden = !needed;
  }

  roleSelect.addEventListener('change', syncSatelliteVisibility);
  syncSatelliteVisibility();
})();
</script>

@endsection
