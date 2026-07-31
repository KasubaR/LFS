@props([
    'authTitle' => 'Account',
    'authSubtitle' => '',
    'breadcrumbLabel' => null,
    'status' => null,
    'split' => false,
    'splitImage' => null,
])

@php
  $breadcrumbLabel = $breadcrumbLabel ?? $authTitle;
  $splitImageUrl = $splitImage ?? asset('images/home/home-hero.jpg');
@endphp

@if($split)
<section class="auth-split">
  <div class="auth-split__visual">
    <img
      src="{{ $splitImageUrl }}"
      alt=""
      class="auth-split__image"
      width="960"
      height="1280"
      decoding="async"
      fetchpriority="high">
    <div class="auth-split__visual-overlay" aria-hidden="true"></div>
    <div class="auth-split__visual-content">
      <a href="{{ url('/') }}" class="auth-split__brand" aria-label="LFS — Home">
        <img src="{{ asset('images/Logo/1024%20512%20LFS_1024.svg') }}" alt="LFS — Lusaka Fitness Squad">
      </a>
      <div class="auth-split__copy">
        <p class="auth-split__eyebrow">Lusaka Fitness Squad</p>
        <p class="auth-split__tagline">Train. Run. Compete. Together.</p>
        <p class="auth-split__motto"><em>We're In This Together.</em></p>
      </div>
      <div class="flag-stripe auth-split__flag" aria-hidden="true">
        <span></span><span></span><span></span><span></span>
      </div>
    </div>
  </div>

  <div class="auth-split__panel">
    <div class="auth-split__panel-inner">
      <nav class="page-breadcrumb auth-split__breadcrumb" aria-label="Breadcrumb">
        <ol>
          <li><a href="{{ url('/') }}">Home</a></li>
          <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
          <li>{{ $breadcrumbLabel }}</li>
        </ol>
      </nav>

      <header class="auth-split__header">
        <h1 class="auth-split__title">{{ $authTitle }}</h1>
        @if($authSubtitle !== '')
          <p class="auth-split__subtitle">{{ $authSubtitle }}</p>
        @endif
      </header>

      <div class="auth-card lfs-form auth-split__card" data-reveal>
        @if(!empty($status))
          <div class="auth-alert auth-alert--success" role="status">
            <i class="fas fa-circle-check" aria-hidden="true"></i>
            <span>{{ $status }}</span>
          </div>
        @endif

        @if($errors->has('_general'))
          <div class="auth-alert auth-alert--error" role="alert">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <span>{{ $errors->first('_general') }}</span>
          </div>
        @endif

        {{ $slot }}
      </div>
    </div>
  </div>
</section>
@else
<section class="page-header">
  <div class="page-header__inner">
    <nav class="page-breadcrumb" aria-label="Breadcrumb">
      <ol>
        <li><a href="{{ url('/') }}">Home</a></li>
        <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
        <li>{{ $breadcrumbLabel }}</li>
      </ol>
    </nav>
    <div>
      <h1 class="text-display-lg">{{ $authTitle }}</h1>
      @if($authSubtitle !== '')
        <p class="page-header__desc">{{ $authSubtitle }}</p>
      @endif
    </div>
    <div class="flag-stripe flag-stripe--on-black mt-6" aria-hidden="true">
      <span></span><span></span><span></span><span></span>
    </div>
  </div>
</section>

<section class="auth-section py-20 px-6 md:px-16 bg-lfs-off-white">
  <div class="auth-section__inner">
    <div class="auth-card lfs-form" data-reveal>

      @if(!empty($status))
        <div class="auth-alert auth-alert--success" role="status">
          <i class="fas fa-circle-check" aria-hidden="true"></i>
          <span>{{ $status }}</span>
        </div>
      @endif

      @if($errors->has('_general'))
        <div class="auth-alert auth-alert--error" role="alert">
          <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
          <span>{{ $errors->first('_general') }}</span>
        </div>
      @endif

      {{ $slot }}

    </div>
  </div>
</section>
@endif

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
