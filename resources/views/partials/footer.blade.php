<!-- ══════════════════════════════════════════════════════
     LFS FOOTER PARTIAL — partials/footer.blade.php
     4-column grid: brand, explore, join, satellites.
     ══════════════════════════════════════════════════════ -->

<footer class="lfs-footer" role="contentinfo">

  <div class="lfs-footer__grid">

    <!-- ── Brand Column ── -->
    <div class="lfs-footer__brand">
      <a href="{{ url('/#hero') }}" class="lfs-footer__logo" aria-label="LFS — Home">
        <img src="{{ asset('images/Logo/1024%20512%20LFS_1024.svg') }}" alt="LFS — Lusaka Fitness Squad" class="lfs-footer__logo-img">
      </a>
      <p class="lfs-footer__desc">
        Lusaka Fitness Squad — <em>We're In This Together.</em><br>
        Zambia's biggest fitness community and running events manager since 2017.
      </p>

      <!-- Social links -->
      <div class="lfs-footer__socials" aria-label="Social media links">
        <a href="https://facebook.com/lfszambia"   class="lfs-footer__social" target="_blank" rel="noopener" aria-label="Facebook">
          <i class="fab fa-facebook-f" aria-hidden="true"></i>
        </a>
        <a href="https://instagram.com/lfszambia"  class="lfs-footer__social" target="_blank" rel="noopener" aria-label="Instagram">
          <i class="fab fa-instagram" aria-hidden="true"></i>
        </a>
        <a href="https://wa.me/260966755326"       class="lfs-footer__social" target="_blank" rel="noopener" aria-label="WhatsApp">
          <i class="fab fa-whatsapp" aria-hidden="true"></i>
        </a>
        <a href="https://twitter.com/lfszambia"    class="lfs-footer__social" target="_blank" rel="noopener" aria-label="X / Twitter">
          <i class="fab fa-x-twitter" aria-hidden="true"></i>
        </a>
      </div>

      <!-- Zambian flag divider -->
      <div class="flag-divider mt-6" aria-hidden="true">
        <span></span><span></span><span></span><span></span>
      </div>

    </div>

    <!-- ── Explore Column ── -->
    <nav class="lfs-footer__col" aria-label="Explore links">
      <h4>Explore</h4>
      <a href="{{ url('/#about') }}">About LFS</a>
      <a href="{{ url('/#activities') }}">Activities</a>
      <a href="{{ url('/#events') }}">Upcoming Events</a>
      <a href="{{ url('/gallery') }}">Gallery</a>
      <a href="{{ url('/news') }}">News &amp; Updates</a>
    </nav>

    <!-- ── Join Column ── -->
    <nav class="lfs-footer__col" aria-label="Join links">
      <h4>Join</h4>
      <a href="https://squidal.com/lfsmembership" target="_blank" rel="noopener noreferrer">Membership</a>
      <a href="{{ url('/#shop') }}">Shop Regalia</a>
      <a href="{{ url('/#contact') }}">Contact Us</a>
      <a href="https://squidal.com/lfsmembership" target="_blank" rel="noopener noreferrer">Membership Fee</a>
    </nav>

    <!-- ── Contact Column ── -->
    <div class="lfs-footer__col">
      <h4>Contact</h4>
      <div class="lfs-footer__contact-snap">
        <a href="mailto:info@lfszambia.run" class="lfs-footer__contact-link">
          <i class="fas fa-envelope" aria-hidden="true"></i> info@lfszambia.run
        </a>
        <a href="{{ url('/contact') }}" class="lfs-footer__contact-link">
          <i class="fas fa-phone-volume" aria-hidden="true"></i> Contact Us
        </a>
        <span class="lfs-footer__contact-link">
          <i class="fas fa-map-pin" aria-hidden="true"></i> CV-6 COMESA Village, Lusaka
        </span>
      </div>
    </div>

  </div><!-- /.lfs-footer__grid -->

  <!-- ── Bottom bar ── -->
  <div class="lfs-footer__bottom">
    <span>&copy; {{ date('Y') }} Lusaka Fitness Squad. All rights reserved.</span>
    <span>
      <a href="mailto:info@lfszambia.run">info@lfszambia.run</a>
      &nbsp;·&nbsp;
      <a href="https://www.lfszambia.run" target="_blank" rel="noopener">www.lfszambia.run</a>
    </span>
  </div>

</footer>

<!-- Zambian flag bottom bar -->
<div class="lfs-footer__flag" aria-hidden="true"></div>
