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
      <h1 class="auth-card__title">Set up two-factor authentication</h1>
      <p class="auth-card__subtitle">
        Electoral Commission, Election Observer, and Super Admin accounts require 2FA.
        Add this secret in your authenticator app, then enter a 6-digit code to confirm.
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

    <div class="auth-card__secret" role="group" aria-label="Authenticator secret">
      <p class="auth-card__secret-label">Manual entry key</p>
      <p class="auth-card__secret-value">
        <code id="totpSecret">{{ $secret }}</code>
      </p>
      <button
        type="button"
        class="admin-btn admin-btn--sm auth-card__copy-btn"
        id="copyTotpSecret"
        data-copy-target="totpSecret"
      >
        <i class="fas fa-copy" aria-hidden="true"></i>
        Copy key
      </button>
      <p class="auth-card__secret-hint">
        In Google Authenticator or similar, choose “Enter a setup key”, then paste the key above.
      </p>
    </div>

    <form class="auth-form" method="post" action="{{ url('/admin/'.$loginSlug.'/2fa/setup') }}">
      @csrf

      <div class="form-group">
        <label class="admin-label" for="code">
          Confirmation code <span class="admin-label__required">*</span>
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
          required
        >
      </div>

      <button type="submit" class="admin-btn admin-btn--primary auth-form__submit">
        <i class="fas fa-shield-halved" aria-hidden="true"></i>
        Enable 2FA &amp; continue
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

<script>
(function () {
  var btn = document.getElementById('copyTotpSecret');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var el = document.getElementById(btn.getAttribute('data-copy-target'));
    if (!el) return;
    var text = el.textContent.trim();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        btn.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Copied';
      });
    }
  });
})();
</script>
@endsection
