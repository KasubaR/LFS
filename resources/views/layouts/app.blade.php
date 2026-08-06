<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ isset($title) ? $title . ' — LFS' : 'LFS — Lusaka Fitness Squad' }}</title>
  <meta name="description" content="{{ $description ?? "Zambia's biggest running community. Train. Run. Compete. Together." }}">
  <meta name="theme-color" content="#0f0f0f">

  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
  <link rel="alternate icon" href="{{ asset('favicon.svg') }}">

  <!-- Open Graph -->
  <meta property="og:title" content="{{ isset($title) ? $title . ' — LFS' : 'LFS — Lusaka Fitness Squad' }}">
  <meta property="og:description" content="{{ $description ?? "Zambia's biggest running community." }}">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://www.lfszambia.run">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,700;1,300&display=swap" rel="stylesheet">

  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <!-- Tailwind (built; run npm run build:css to regenerate) -->
  <link rel="stylesheet" href="{{ asset('css/tailwind-build.css') }}">
  <!-- LFS custom utilities and tokens -->
  <link rel="stylesheet" href="{{ asset('css/tailwind.css') }}">
  <link rel="stylesheet" href="{{ asset('css/main.css') }}">
  <link rel="stylesheet" href="{{ asset('css/cookie-banner.css') }}">

  <!-- Page-specific styles (optional — trusted controller output only, never from user input) -->
  {!! ($styles ?? '') . ($extraStyles ?? '') !!}
</head>

<body class="antialiased{{ isset($bodyClass) && $bodyClass !== '' ? ' ' . $bodyClass : '' }}">

  <!-- ── NAVBAR PARTIAL ── -->
  @unless($hideNavbar ?? false)
    @include('partials.navbar')
  @endunless

  <!-- ── MAIN CONTENT ── -->
  <main id="main-content">
    @yield('content')
  </main>

  <!-- ── FOOTER PARTIAL ── -->
  @php $cartCount = $cartCount ?? 0; @endphp
  @unless($hideFooter ?? false)
    @include('partials.footer')
  @endunless

  <!-- ── FLOATING CART FAB (hidden when cart empty) ── -->
  @unless($hideFooter ?? false)
  <button class="lfs-cart-fab{{ $cartCount === 0 ? ' lfs-cart-fab--hidden' : '' }}" onclick="window.location='/shop/cart'" aria-label="View cart ({{ $cartCount }} items)">
    <i class="fas fa-shopping-bag"></i>
    <span class="lfs-cart-fab__count"{{ $cartCount === 0 ? ' style="display:none"' : '' }}>{{ $cartCount }}</span>
  </button>
  @endunless

  <!-- Cookie consent banner -->
  @include('partials.cookie-banner')
  <script src="{{ asset('js/cookie-banner.js') }}"></script>

  <!-- Global JS -->
  <script>window.__LFS_CART_COUNT__ = {{ $cartCount }};</script>
  <script src="{{ asset('js/input-sanitizer.js') }}"></script>
  <script src="{{ asset('js/main.js') }}"></script>
  <script src="{{ asset('js/cart.js') }}"></script>
  <script src="{{ asset('js/password-requirements.js') }}"></script>

  <!-- Page-specific scripts (optional — trusted controller output only, never from user input) -->
  {!! ($scripts ?? '') . ($extraScripts ?? '') !!}

</body>
</html>
