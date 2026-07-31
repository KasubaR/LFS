@extends('layouts.admin')

@section('content')
@php
$scopes = $scopes ?? [];
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'API Keys', 'url' => '/admin/api-clients'],
    ['label' => 'Issue'],
];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/api-clients') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to API Keys
  </a>
  <h2 class="admin-page-header__heading">Issue API Key</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="/admin/api-clients/create">
    @csrf

    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}">
      <label for="name" class="admin-label">Event website name</label>
      <input type="text" id="name" name="name" required maxlength="150"
             value="{{ old('name') }}" class="admin-input"
             placeholder="e.g. Lusaka Marathon 2026">
      @error('name')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('contact_email') ? ' form-group--error' : '' }}">
      <label for="contact_email" class="admin-label">Developer contact email <span class="text-muted">(optional)</span></label>
      <input type="email" id="contact_email" name="contact_email" maxlength="255"
             value="{{ old('contact_email') }}" class="admin-input">
      @error('contact_email')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('scopes') ? ' form-group--error' : '' }}">
      <span class="admin-label">Permissions</span>
      @foreach($scopes as $value => $label)
        <label style="display:block;margin:.35rem 0;font-weight:normal;">
          <input type="checkbox" name="scopes[]" value="{{ $value }}"
                 {{ in_array($value, old('scopes', ['members:verify']), true) ? 'checked' : '' }}>
          <code>{{ $value }}</code> &mdash; {{ $label }}
        </label>
      @endforeach
      @error('scopes')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('rate_limit_per_minute') ? ' form-group--error' : '' }}">
      <label for="rate_limit_per_minute" class="admin-label">Rate limit (requests per minute)</label>
      <input type="number" id="rate_limit_per_minute" name="rate_limit_per_minute" min="1" max="10000"
             value="{{ old('rate_limit_per_minute', 60) }}" class="admin-input">
      <p class="text-muted" style="font-size:.85rem;margin-top:.25rem;">
        60 suits a normal event checkout. Lower it for small events &mdash; this is also what limits
        guessing attempts against member details.
      </p>
      @error('rate_limit_per_minute')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('allowed_ips') ? ' form-group--error' : '' }}">
      <label for="allowed_ips" class="admin-label">IP allowlist <span class="text-muted">(optional)</span></label>
      <textarea id="allowed_ips" name="allowed_ips" rows="3" class="admin-input"
                placeholder="One IP per line. Leave blank to allow any.">{{ old('allowed_ips') }}</textarea>
      <p class="text-muted" style="font-size:.85rem;margin-top:.25rem;">
        If the event site has a fixed server IP, adding it here means a stolen key is still unusable
        from anywhere else.
      </p>
      @error('allowed_ips')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('expires_at') ? ' form-group--error' : '' }}">
      <label for="expires_at" class="admin-label">Expires on <span class="text-muted">(optional)</span></label>
      <input type="date" id="expires_at" name="expires_at" value="{{ old('expires_at') }}" class="admin-input">
      <p class="text-muted" style="font-size:.85rem;margin-top:.25rem;">
        Set this to shortly after the event so the key dies on its own.
      </p>
      @error('expires_at')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">Issue Key</button>
  </form>
</div>

@endsection
