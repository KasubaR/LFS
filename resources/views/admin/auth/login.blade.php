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
      <h1 class="auth-card__title">Admin Sign In</h1>
      <p class="auth-card__subtitle">
        Sign in with your admin email and password.
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

    <form class="auth-form" method="post" action="{{ url('/admin/'.$loginSlug) }}">
      <input type="hidden" name="_token" value="{{ $csrfToken ?? csrf_token() }}">

      <div class="form-group">
        <label class="admin-label" for="email">
          Email <span class="admin-label__required">*</span>
        </label>
        <input
          id="email"
          name="email"
          type="email"
          class="admin-input"
          value="{{ $email ?? old('email') }}"
          autocomplete="username"
          required
        >
      </div>

      <div class="form-group auth-form__password-wrap">
        <label class="admin-label" for="password">
          Password <span class="admin-label__required">*</span>
        </label>
        <div class="auth-form__input-wrap">
          <input
            id="password"
            name="password"
            type="password"
            class="admin-input"
            autocomplete="current-password"
            required
          >
          <button
            type="button"
            class="auth-form__eye"
            data-toggle-password="password"
            aria-label="Show password"
          >
            <i class="fas fa-eye-slash" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="admin-btn admin-btn--primary auth-form__submit">
        <i class="fas fa-lock" aria-hidden="true"></i>
        Sign In
      </button>
    </form>

    <p class="auth-card__footer-note">
      <a href="{{ url('/') }}" class="auth-card__back-link">
        <i class="fas fa-arrow-left" aria-hidden="true"></i>
        Back to site
      </a>
    </p>

  </div>

</div>

<script>
(function () {
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      var isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      var icon = btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('fa-eye', isPassword);
        icon.classList.toggle('fa-eye-slash', !isPassword);
      }
      btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
  });
})();
</script>

@endsection
