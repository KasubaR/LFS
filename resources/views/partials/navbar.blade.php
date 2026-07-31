<!-- ══════════════════════════════════════════════════════
     LFS NAVBAR PARTIAL — partials/navbar.blade.php
     Fixed top nav with scroll-shrink, active links & mobile drawer.
     ══════════════════════════════════════════════════════ -->
@php
  $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $uriPath = rtrim($uriPath, '/') ?: '/';
  $base    = '';
  $relativePath = $uriPath;
  if ($base && str_starts_with($uriPath, $base)) {
    $relativePath = substr($uriPath, strlen($base)) ?: '/';
  }
  $navIsHome    = $relativePath === '/' || $relativePath === '';
  $navIsShop    = str_starts_with($relativePath, '/shop');
  $navIsAbout   = str_starts_with($relativePath, '/about');
  $navIsGallery = str_starts_with($relativePath, '/gallery');
  $navIsContact = str_starts_with($relativePath, '/contact');
  $navIsAuth    = in_array($relativePath, ['/login', '/create-account', '/forgot-password', '/account', '/email/verify', '/membership/apply'], true)
      || str_starts_with($relativePath, '/reset-password');
@endphp

<nav class="lfs-nav" role="navigation" aria-label="Main navigation">

  <!-- Logo → home -->
  <a href="{{ url('/#hero') }}" class="lfs-nav__logo" aria-label="LFS — Home">
    <img src="{{ asset('images/Logo/1024%20512%20LFS_1024.svg') }}" alt="LFS — Lusaka Fitness Squad" />
  </a>

  <!-- Desktop links (centered) -->
  <ul class="lfs-nav__links" role="list">
    <li class="lfs-nav__item lfs-nav__item--has-dropdown">
      <a href="{{ url('/#hero') }}" @class(['nav-link', 'nav-link--active' => $navIsHome])>Home <i class="fas fa-chevron-down lfs-nav__chevron" aria-hidden="true"></i></a>
      <ul class="lfs-nav__dropdown" role="list">
        <li><a href="{{ url('/#activities') }}">Activities</a></li>
        <li><a href="{{ url('/#events') }}">Events</a></li>
        <li><a href="{{ url('/#news') }}">News</a></li>
      </ul>
    </li>
    <li><a href="{{ url('/shop') }}" @class(['nav-link', 'nav-link--active' => $navIsShop])>Shop</a></li>
    <li><a href="{{ url('/about') }}" @class(['nav-link', 'nav-link--active' => $navIsAbout])>About Us</a></li>
    <li><a href="{{ url('/gallery') }}" @class(['nav-link', 'nav-link--active' => $navIsGallery])>Gallery</a></li>
    <li><a href="{{ url('/contact') }}" @class(['nav-link', 'nav-link--active' => $navIsContact])>Contact Us</a></li>
  </ul>

  <!-- Desktop auth actions (right) -->
  <div class="lfs-nav__actions">
    @guest
    <a href="{{ url('/create-account') }}" class="lfs-nav__cta">Join Now</a>
    <a href="{{ url('/login') }}" @class(['nav-link', 'nav-link--active' => $navIsAuth && $relativePath === '/login'])>Sign In</a>
    @else
    <a href="{{ url('/account') }}" @class(['nav-link', 'nav-link--active' => $relativePath === '/account'])>Account</a>
    <form action="{{ url('/logout') }}" method="post" style="display:inline;">
      @csrf
      <button type="submit" class="lfs-nav__cta" style="background:transparent;border:1px solid var(--green);color:var(--green);cursor:pointer;">Sign Out</button>
    </form>
    @endguest
  </div>

  <!-- Hamburger (mobile) -->
  <button class="lfs-nav__hamburger" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobile-nav">
    <span></span>
    <span></span>
    <span></span>
  </button>

</nav>

<!-- ── MOBILE NAV DRAWER (same order & links as desktop) ── -->
<div id="mobile-nav" class="lfs-nav__mobile" role="dialog" aria-label="Mobile navigation" aria-hidden="true">
  <a href="{{ url('/#hero') }}" @class(['lfs-nav__mobile-link--active' => $navIsHome])>Home</a>
  <a href="{{ url('/#activities') }}">Activities</a>
  <a href="{{ url('/#events') }}">Events</a>
  <a href="{{ url('/#news') }}">News</a>
  <a href="{{ url('/shop') }}" @class(['lfs-nav__mobile-link--active' => $navIsShop])>Shop</a>
  <a href="{{ url('/about') }}" @class(['lfs-nav__mobile-link--active' => $navIsAbout])>About Us</a>
  <a href="{{ url('/gallery') }}" @class(['lfs-nav__mobile-link--active' => $navIsGallery])>Gallery</a>
  <a href="{{ url('/contact') }}" @class(['lfs-nav__mobile-link--active' => $navIsContact])>Contact Us</a>
  @guest
  <a href="{{ url('/create-account') }}" class="lfs-nav__mobile-cta">
    Join Now
  </a>
  <a href="{{ url('/login') }}">Sign In</a>
  @else
  <a href="{{ url('/account') }}">Account</a>
  <form action="{{ url('/logout') }}" method="post" style="margin-top:0.5rem;">
    @csrf
    <button type="submit" class="lfs-nav__mobile-cta" style="width:100%;background:transparent;border:1px solid var(--green);color:var(--green);cursor:pointer;">
      Sign Out
    </button>
  </form>
  @endguest
</div>
