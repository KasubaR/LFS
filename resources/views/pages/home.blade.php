@extends('layouts.app')

@section('content')
@php
/**
 * LFS HOME PAGE — pages/home.php
 *
 * Variables expected from controller:
 *   $events         array   — upcoming events (may be empty)
 *   $galleryPreview array   — gallery photos for preview (may be empty)
 *   $products       array   — shop products (may be empty; shop preview shows empty state)
 *   $posts          array   — news/blog posts (may be empty, falls back to defaults)
 *   $heroFeaturedEvents  list<array{ title, dateLine, link, bannerImage }> — upcoming featured events
 *                          (banners are prepended to the hero image slider in the same order)
 */
$events         = $events         ?? [];
$galleryPreview = $galleryPreview ?? [];
$products       = $products       ?? [];
$posts          = $posts          ?? [];
$heroSlides     = $heroSlides     ?? [];
$heroFeaturedEvents = $heroFeaturedEvents ?? [];

// Build ordered URL list: featured event banner(s) first, then gallery slider (or static fallback)
$_heroDefault = $heroImage ?? '/images/home/home-hero.jpg';
$_galleryUrls = array_values(array_filter(array_map(function (array $s): string {
    return $s['urls']['large'] ?? $s['urls']['original'] ?? $s['urls']['medium'] ?? '';
}, $heroSlides)));
if (empty($_galleryUrls)) {
    $_galleryUrls = [$_heroDefault];
}

$_feBanners = [];
foreach ($heroFeaturedEvents as $_fe) {
    $_b = (string)($_fe['bannerImage'] ?? '');
    if ($_b === '') {
        continue;
    }
    $_feBanners[] = preg_match('#^https?://#i', $_b) === 1
        ? $_b
        : asset(ltrim($_b, '/'));
}
$_slideUrls   = array_values(array_merge($_feBanners, $_galleryUrls));
$_heroFeatureCount = count($_feBanners);
@endphp

<!-- ══════════════════════════════════════════════
     1. HERO
     ══════════════════════════════════════════════ -->
<section id="hero"
  class="home-hero home-hero--compact relative overflow-hidden flex flex-col justify-center">

  <div class="home-hero__bg" id="heroSliderBg" aria-hidden="true">
    @foreach($_slideUrls as $_i => $_url)
      <img
        src="{{ $_url }}"
        class="home-hero__slide{{ $_i === 0 ? ' home-hero__slide--active' : '' }}"
        alt=""
        loading="{{ $_i === 0 ? 'eager' : 'lazy' }}"
        aria-hidden="true"
      >
    @endforeach
  </div>

  <div class="absolute inset-0 z-[1]" style="background:linear-gradient(to right, rgba(0,0,0,0.65) 0%, rgba(0,0,0,0.35) 55%, rgba(0,0,0,0.1) 100%)" aria-hidden="true"></div>

  <div class="relative z-10 px-6 lg:px-16 py-16 lg:py-20 max-w-4xl">
    <div class="home-hero__content">
      @if($_heroFeatureCount > 0)
      @php
        $_kicker = $_heroFeatureCount > 1 ? 'Featured events' : 'Featured event';
      @endphp
      <div
        class="home-hero__featured-wrap"
        data-hero-featured-count="{{ $_heroFeatureCount }}"
        aria-label="{{ $_kicker }}"
        role="group">
        <p class="home-hero__featured-kicker mt-6 mb-2 animate-fadeUp">
          {{ $_kicker }}
        </p>
        <div class="home-hero__featured-panels">
          @foreach($heroFeaturedEvents as $_pi => $_fe)
          @php
            $_hfeTitle = trim((string)($_fe['title'] ?? '')) !== '' ? (string) $_fe['title'] : 'Event';
            $_hfeDate  = (string)($_fe['dateLine'] ?? '');
            $_hfeLink  = (string)($_fe['link'] ?? '');
            $_active   = $_pi === 0 ? ' home-hero__featured-panel--active' : '';
          @endphp
          <div class="home-hero__featured home-hero__featured-panel{{ $_active }}" data-featured-panel-index="{{ $_pi }}">
            <h2 class="home-hero__featured-title font-['Bebas_Neue'] text-5xl sm:text-6xl lg:text-7xl leading-tight text-white mt-0 max-w-4xl animate-fadeUp">
              {{ $_hfeTitle }}
            </h2>
            @if($_hfeDate !== '' && $_hfeDate !== '—')
            <p class="home-hero__featured-date mt-5 text-white text-base font-light leading-relaxed max-w-xl animate-fadeUp">
              {{ $_hfeDate }}
            </p>
            @endif
            <div class="home-hero__featured-actions flex flex-wrap items-center gap-4 mt-6 animate-fadeUp">
            @if($_hfeLink !== '')
              <a href="{{ $_hfeLink }}" class="btn btn-primary">
                View Event Details
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </a>
              <a href="{{ url('/events') }}" class="btn btn-outline">
                All events
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </a>
            @else
              <a href="{{ url('/events') }}" class="btn btn-primary">
                All events
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </a>
            @endif
            </div>
          </div>
          @endforeach
        </div>
      </div>
      @endif

      <div id="heroGenericContent">
        <h1 class="font-['Bebas_Neue'] text-5xl sm:text-6xl lg:text-7xl leading-tight text-white mt-6 animate-fadeUp"
          style="animation-delay:0.15s">
          Zambia's Biggest<br>
          Running Community
        </h1>

        <p class="mt-5 text-white text-base font-light leading-relaxed max-w-xl animate-fadeUp"
          style="animation-delay:0.3s">
          Train. Run. Compete. Together.&nbsp; LFS is a vibrant squad of runners, dreamers and doers
          pushing each other forward, every single stride, across six satellites in Lusaka.
        </p>

        <div class="flex flex-wrap items-center gap-4 mt-6 animate-fadeUp" style="animation-delay:0.45s">
          <a href="https://squidal.com/lfsmembership" class="btn btn-primary" target="_blank" rel="noopener noreferrer">
            Join LFS
            <i class="fas fa-arrow-right" aria-hidden="true"></i>
          </a>
          <a href="{{ url('/shop') }}" class="btn btn-outline">
            Shop
            <i class="fas fa-arrow-right" aria-hidden="true"></i>
          </a>
        </div>

        <div class="stat-row animate-fadeUp mt-6" style="animation-delay:0.6s" aria-label="LFS at a glance">
          <div class="stat-item">
            <div class="stat-item__num" data-count="6" data-suffix="">6</div>
            <div class="stat-item__label">Satellites</div>
          </div>
          <div class="stat-item">
            <div class="stat-item__num" data-count="7" data-suffix="+">7+</div>
            <div class="stat-item__label">Years Running</div>
          </div>
          <div class="stat-item">
            <div class="stat-item__num" data-count="52" data-suffix="">52</div>
            <div class="stat-item__label">LSDs / Year</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  @if(count($_slideUrls) > 1)
  <!-- Dot indicators -->
  <div class="hero-slider-dots" aria-hidden="true" id="heroSliderDots">
    @foreach($_slideUrls as $_i => $_url)
      <button class="hero-slider-dot{{ $_i === 0 ? ' hero-slider-dot--active' : '' }}"
              type="button" data-index="{{ $_i }}"></button>
    @endforeach
  </div>
  @endif

</section>

<script>
(function () {
  const slides = document.querySelectorAll('.home-hero__slide');
  const dots   = document.querySelectorAll('.hero-slider-dot');
  if (slides.length <= 1) return;

  const wrap = document.querySelector('.home-hero__featured-wrap');
  const featuredCount = wrap ? parseInt(wrap.getAttribute('data-hero-featured-count') || '0', 10) : 0;
  const panels = document.querySelectorAll('.home-hero__featured-panel');
  const genericContent = document.getElementById('heroGenericContent');

  function syncFeaturedPanels(slideIndex) {
    const isFeaturedSlide = wrap && featuredCount > 0 && slideIndex < featuredCount;

    if (isFeaturedSlide) {
      wrap.classList.remove('home-hero__featured-wrap--conceal');
      panels.forEach(function (p) {
        var pi = parseInt(p.getAttribute('data-featured-panel-index') || '0', 10);
        p.classList.toggle('home-hero__featured-panel--active', pi === slideIndex);
      });
      if (genericContent) genericContent.style.display = 'none';
    } else {
      if (wrap) wrap.classList.add('home-hero__featured-wrap--conceal');
      if (genericContent) genericContent.style.display = '';
    }
  }

  let current   = 0;
  let timer     = null;
  const INTERVAL = 6000; // ms between slides

  function goTo(idx) {
    slides[current].classList.remove('home-hero__slide--active');
    if (dots[current]) dots[current].classList.remove('hero-slider-dot--active');
    current = (idx + slides.length) % slides.length;
    slides[current].classList.add('home-hero__slide--active');
    if (dots[current]) dots[current].classList.add('hero-slider-dot--active');
    syncFeaturedPanels(current);
  }

  function startTimer() {
    clearInterval(timer);
    timer = setInterval(function () { goTo(current + 1); }, INTERVAL);
  }

  // Dot clicks
  dots.forEach(function (dot) {
    dot.addEventListener('click', function () {
      goTo(parseInt(this.dataset.index, 10));
      startTimer(); // reset interval on manual nav
    });
  });

  syncFeaturedPanels(0);
  startTimer();
}());
</script>


<!-- ══════════════════════════════════════════════
     2. ABOUT SNAPSHOT
     ══════════════════════════════════════════════ -->
<section id="about" class="py-20 px-6 md:px-16 grid md:grid-cols-2 gap-12 items-center bg-lfs-warm-white">

  <!-- Stacked images -->
  <div class="relative h-[500px]">
    <img src="{{ asset('/images/about/about-1.jpg') }}"
      alt="Who we are, LFS community" class="w-3/4 h-[420px] object-cover rounded absolute top-0 left-0 shadow-lg" loading="lazy">
    <img src="{{ asset('/images/about/about-2.jpg') }}"
      alt="LFS community"
      class="w-3/5 h-72 object-cover rounded absolute bottom-0 right-0 shadow-lg"
      loading="lazy">
  </div>

  <!-- Copy -->
  <div>
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl leading-tight">
      More Than A<br>Running Club
    </h2>
    <p class="text-[#6b6b6b] text-base leading-relaxed mt-4">
      LFS is a vibrant community of fitness enthusiasts from different parts of the world, coming together
      to stay active, support one another, and grow stronger as a team. Whether you're just starting
      out or a seasoned runner, there's a place for you here.
    </p>

    <!-- Flag-coloured value pillars -->
    <div class="grid grid-cols-2 gap-4 mt-6">
      <div class="pillar">
        <div class="pillar__title"><i class="fas fa-medal" style="color:var(--flag-green)" aria-hidden="true"></i>
          Biggest Club</div>
        <p class="pillar__body">Zambia's #1 fitness community with runners across Lusaka</p>
      </div>
      <div class="pillar red">
        <div class="pillar__title"><i class="fas fa-calendar-star" style="color:var(--flag-red)" aria-hidden="true"></i>
          Pro Events</div>
        <p class="pillar__body">7+ years managing Zambia's premier running events</p>
      </div>
      <div class="pillar orange">
        <div class="pillar__title"><i class="fas fa-universal-access" style="color:var(--flag-orange)"
            aria-hidden="true"></i> Inclusive</div>
        <p class="pillar__body">All paces, all levels, everyone belongs at LFS</p>
      </div>
      <div class="pillar">
        <div class="pillar__title"><i class="fas fa-chart-line" style="color:var(--flag-green)" aria-hidden="true"></i>
          Structured</div>
        <p class="pillar__body">Satellite captains run weekly training programs</p>
      </div>
    </div>

    <a href="https://squidal.com/lfsmembership" class="btn btn-primary mt-8" target="_blank" rel="noopener noreferrer">
      <i class="fas fa-id-card" aria-hidden="true"></i> Become a Member
    </a>
  </div>

</section>


<!-- ══════════════════════════════════════════════
     3. ACTIVITIES HIGHLIGHTS
     ══════════════════════════════════════════════ -->
<section id="activities" class="py-20 px-6 md:px-16 bg-black text-white">
  <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl text-white" data-reveal>
    Squad<br>Activities
  </h2>

  <div class="grid md:grid-cols-3 gap-px mt-12"
    style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.08)">

    <!-- LSD Runs (wide) -->
    <div class="activity-card md:col-span-2" data-reveal data-reveal-delay="1">
      <div class="activity-card__num">01</div>
      <i class="activity-card__icon fas fa-person-running" aria-hidden="true"></i>
      <div class="activity-card__title">Saturday LSD Runs</div>
      <p class="activity-card__body">
        Every Saturday, all six satellites unite for a Long Slow Distance run at a rotating host location.
        Inclusive, low-pressure, and built for all fitness levels, the weekly highlight of LFS life.
      </p>
    </div>

    <!-- Weekly satellite runs -->
    <div class="activity-card" data-reveal data-reveal-delay="2">
      <div class="activity-card__num">02</div>
      <i class="activity-card__icon fas fa-calendar-alt" aria-hidden="true"></i>
      <div class="activity-card__title">Weekly Satellite Runs</div>
      <p class="activity-card__body">
        Training sessions within your local satellite every week, guided by your captain's structured program.
      </p>
    </div>

    <!-- Race events -->
    <div class="activity-card" data-reveal data-reveal-delay="1">
      <div class="activity-card__num">03</div>
      <i class="activity-card__icon fas fa-trophy" aria-hidden="true"></i>
      <div class="activity-card__title">Race Events</div>
      <p class="activity-card__body">
        LFS manages Zambia's biggest running events, 7+ years of delivering world-class race experiences
        across multiple distances.
      </p>
    </div>

    <!-- Volunteering -->
    <div class="activity-card" data-reveal data-reveal-delay="2">
      <div class="activity-card__num">04</div>
      <i class="activity-card__icon fas fa-hand-holding-heart" aria-hidden="true"></i>
      <div class="activity-card__title">Volunteering</div>
      <p class="activity-card__body">
        Every member serves at least once per year. LFS runs because members show up and give back.
      </p>
    </div>

    <!-- Community events -->
    <div class="activity-card" data-reveal data-reveal-delay="3">
      <div class="activity-card__num">05</div>
      <i class="activity-card__icon fas fa-champagne-glasses" aria-hidden="true"></i>
      <div class="activity-card__title">Community Events</div>
      <p class="activity-card__body">
        Annual AGM, social gatherings, celebration runs, and more. A community that thrives beyond the road.
      </p>
    </div>

  </div>

</section>


<!-- ══════════════════════════════════════════════
     4. UPCOMING EVENTS
     ══════════════════════════════════════════════ -->
<section id="events" class="py-20 px-6 md:px-16 bg-lfs-off-white">

  <div class="flex flex-wrap justify-between items-end gap-4 mb-12">
    <div data-reveal>
      <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl">Event<br>Calendar</h2>
    </div>
    <a href="{{ url('/events') }}" class="btn btn-primary" data-reveal="right">View All Events</a>
  </div>

  <div class="events-list{{ empty($events) ? ' events-list--empty' : '' }}" role="list">
    @if(empty($events))
      <div class="events-empty">
        <div class="events-empty__icon"><i class="fas fa-calendar-xmark" aria-hidden="true"></i></div>
        <div class="events-empty__heading">No Upcoming Events</div>
        <p class="events-empty__desc">Check back soon, new events are added regularly. View the full calendar for past events.</p>
        <a href="{{ url('/events') }}" class="btn btn-primary events-empty__cta">View Events</a>
      </div>
    @else
      @foreach(array_slice($events, 0, 5) as $idx => $ev)
        @php
          $dateParts = $ev['date'] ? explode(' ', $ev['date']) : [];
          $dayNum    = isset($dateParts[1]) ? rtrim($dateParts[1], ',') : '--';
          $month     = isset($dateParts[2]) ? strtoupper(substr($dateParts[2], 0, 3)) : 'TBA';
          $tagColor  = $ev['tagColor'] ?? '';
          $tagClass  = in_array($tagColor, ['orange', 'red', 'gold']) ? $tagColor : '';
          $delay     = ($idx % 3) + 1;
        @endphp
        <article class="event-card" data-reveal data-reveal-delay="{{ $delay }}" role="listitem">
          <div class="event-card__date-block">
            <span class="event-card__month">{{ $month }}</span>
            <span class="event-card__day">{{ $dayNum }}</span>
          </div>
          <div class="event-card__body">
            <div class="event-card__tags">
              <span class="badge {{ $tagClass }}">
                {{ $ev['tag'] ?? 'Run' }}
              </span>
              <span class="event-card__distance">
                <i class="fas fa-route mr-1" aria-hidden="true"></i>
                {{ $ev['distance'] ?? '' }}
              </span>
            </div>
            <h3 class="event-card__title">{{ $ev['title'] }}</h3>
            <p class="event-card__meta">
              <i class="fas fa-map-pin mr-1" aria-hidden="true"></i>{{ $ev['location'] ?? '' }}
              &nbsp;·&nbsp;
              <i class="fas fa-calendar mr-1" aria-hidden="true"></i>{{ $ev['date'] ?? '' }}
            </p>
          </div>
          <div class="event-card__actions">
            <a href="{{ url($ev['link'] ?? '/events') }}" class="btn btn-primary btn-sm">
              View <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
          </div>
        </article>
      @endforeach
    @endif
  </div>

</section>


<!-- ══════════════════════════════════════════════
     5. MEMBERSHIP CTA
     ══════════════════════════════════════════════ -->
<section id="membership" class="py-20 px-6 md:px-16 text-white relative overflow-hidden"
  style="background:var(--dark-green)">
  <!-- Background display text -->
  <div class="absolute font-['Bebas_Neue'] text-[30vw] right-0 top-0 pointer-events-none select-none leading-none"
    style="color:rgba(255,255,255,0.04)" aria-hidden="true">LFS</div>

  <div class="grid md:grid-cols-2 gap-12 items-center relative z-10">

    <!-- Benefits copy -->
    <div data-reveal="left">
      <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl text-white">Become A<br>Full Member</h2>
      <p class="text-white/60 text-base leading-relaxed mt-4">
        Your annual membership keeps you connected to the full LFS experience,
        from official WhatsApp groups to voting rights at the AGM.
      </p>
      <ul class="membership-list" aria-label="Membership benefits">
        <li>
          <span class="membership-list__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
          Access to "LFS Full Members" WhatsApp group
        </li>
        <li>
          <span class="membership-list__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
          Access to "The HUB" announcements channel
        </li>
        <li>
          <span class="membership-list__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
          Voting rights at the Annual General Meeting
        </li>
        <li>
          <span class="membership-list__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
          Priority registration for LFS-managed events
        </li>
        <li>
          <span class="membership-list__icon"><i class="fas fa-check" aria-hidden="true"></i></span>
          Official LFS community recognition &amp; jersey eligibility
        </li>
      </ul>
    </div>

    <!-- Pricing card -->
    <div class="price-card" data-reveal="right">
      <div class="text-center text-xs tracking-widest" style="color:var(--green-bright)">Annual Membership 2026</div>
      <div class="text-center mt-4">
        <span class="font-['Bebas_Neue'] text-5xl align-top mt-2 inline-block"
          style="color:var(--green-bright)">K</span>
        <span class="font-['Bebas_Neue'] text-7xl text-white">1,000</span>
      </div>
      <div class="text-sm text-center mt-1" style="color:rgba(255,255,255,0.4)">per year · renewed annually</div>
      <hr class="my-5" style="border-color:rgba(255,255,255,0.1)">
      <a href="https://squidal.com/lfsmembership" class="btn btn-primary w-full justify-center" target="_blank" rel="noopener noreferrer">
        Pay Membership Fee
      </a>
      <p class="text-center mt-3 text-xs" style="color:rgba(255,255,255,0.4)">
        Contact the LFS Treasurer to make payment
      </p>
      <hr class="my-4" style="border-color:rgba(255,255,255,0.1)">
      <div class="badge gold inline-flex">
        <i class="far fa-clock mr-1" aria-hidden="true"></i> Deadline: End of April each year
      </div>
    </div>

  </div>
</section>


<!-- ══════════════════════════════════════════════
     7. SHOP PREVIEW
     ══════════════════════════════════════════════ -->
@include('partials.shop-preview')


<!-- ══════════════════════════════════════════════
     8. NEWS & UPDATES
     ══════════════════════════════════════════════ -->
@php $newsBase = url('/news'); @endphp
<section id="news" class="py-20 px-6 md:px-16 bg-lfs-warm-white">

  <div class="flex flex-wrap justify-between items-end gap-4 mb-12">
    <div data-reveal>
      <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl">News &amp;<br>Updates</h2>
    </div>
    @if(!empty($posts))
    <a href="{{ $newsBase }}" class="btn btn-outline" style="color:var(--black);border-color:rgba(0,0,0,0.2)" data-reveal="right">View All Posts</a>
    @endif
  </div>

  @if(empty($posts))
  <div class="news-empty" data-reveal>
    <h3 class="news-empty__title">No stories yet</h3>
    <p class="news-empty__text">
      Club news, race reports, and updates will appear here. Check back soon or visit the news page.
    </p>
    <a href="{{ $newsBase }}" class="btn btn-outline news-empty__cta" style="color:var(--black);border-color:rgba(0,0,0,0.2)">
      Visit News <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i>
    </a>
  </div>
  @else
  <div class="grid md:grid-cols-3 gap-6">
    @foreach(array_slice($posts, 0, 3) as $idx => $post)
      <article class="news-card" data-reveal data-reveal-delay="{{ $idx + 1 }}">
        <a href="{{ !empty($post['link']) ? url($post['link']) : '#' }}" class="news-card__image-link" aria-label="Read: {{ $post['title'] }}">
          <div class="news-card__image">
            <img src="{{ $post['image'] }}" alt="{{ $post['title'] }}" loading="lazy">
            <div class="news-card__category">{{ $post['category'] }}</div>
          </div>
        </a>
        <div class="news-card__body">
          <div class="news-card__date">
            <i class="fas fa-calendar-alt mr-1" aria-hidden="true"></i>
            {{ $post['date'] }}
          </div>
          <h3 class="news-card__title">
            <a href="{{ !empty($post['link']) ? url($post['link']) : '#' }}">{{ $post['title'] }}</a>
          </h3>
          <p class="news-card__excerpt">{{ $post['excerpt'] }}</p>
          <a href="{{ !empty($post['link']) ? url($post['link']) : '#' }}" class="news-card__read-more">
            Read More <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i>
          </a>
        </div>
      </article>
    @endforeach
  </div>
  @endif
</section>

@endsection
