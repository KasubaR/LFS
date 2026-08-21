@extends('layouts.admin')

@section('content')
@php
$loginSlug = config('admin.login_slug', 'door');
@endphp

<div class="auth-wrap">
  <div class="auth-card">

    <header class="auth-card__header">
      <div class="auth-logo">
        <img src="/images/Logo/1024%20512%20LFS_512x512%20.svg" alt="LFS — Lusaka Fitness Squad" class="auth-logo__img">
      </div>
      <h1 class="auth-card__title">Two-factor authentication</h1>
      <p class="auth-card__subtitle">
        Enter the 6-digit code from your authenticator app to continue.
      </p>
    </header>

    @if(!empty($error))
      <div class="auth-card__error">
        <div class="sys-notif sys-notif--error" role="alert">
          <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
          <span>{{ $error }}</span>
        </div>
      </div>
    @endif

    <form class="auth-form" method="post" action="{{ url('/admin/'.$loginSlug.'/2fa') }}">
      @csrf

      <div class="form-group">
        <label class="admin-label" for="code">
          Authenticator code <span class="admin-label__required">*</span>
        </label>
        <input
          id="code"
          name="code"
          type="text"
          class="admin-input"
          inputmode="numeric"
          pattern="[0-9]{6}"
          maxlength="6"
          autocomplete="one-time-code"
          placeholder="000000"
          autofocus
          required
        >
      </div>

      <button type="submit" class="admin-btn admin-btn--primary auth-form__submit">
        <i class="fas fa-lock" aria-hidden="true"></i>
        Verify
      </button>
    </form>

    <p class="auth-card__footer-note">
      <a href="{{ url('/admin/'.$loginSlug) }}" class="auth-card__back-link">
        <i class="fas fa-arrow-left" aria-hidden="true"></i>
        Back to sign in
      </a>
    </p>

  </div>
</div>
@endsection
