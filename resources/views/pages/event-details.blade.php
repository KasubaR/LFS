@extends('layouts.app')

@section('content')
@php
/* ============================================================
   LFS — Lusaka Fitness Squad
   views/pages/event-details.php

   Variables provided by the event controller:
     $event  — single event row/array from the DB
   ============================================================ */

/* ── Helpers ─────────────────────────────────────────────── */
$now = new DateTime();
$ev  = $event ?? [];

if (! function_exists('getEventStatus')) {
  function getEventStatus(array $ev, DateTime $now): array {
    $eDate    = !empty($ev['eventDate'])          ? new DateTime($ev['eventDate'])          : null;
    $regOpen  = !empty($ev['registrationOpen'])   ? new DateTime($ev['registrationOpen'])   : null;
    $regClose = !empty($ev['registrationClose'])  ? new DateTime($ev['registrationClose'])  : null;

    if (!$eDate)                                                          return ['key' => 'upcoming',  'label' => 'Upcoming'];
    if ($eDate < $now)                                                    return ['key' => 'completed', 'label' => 'Completed'];
    if (!empty($ev['registrationFull']))                                  return ['key' => 'full',      'label' => 'Registration Full'];
    if ($regClose && $regClose < $now)                                    return ['key' => 'closed',    'label' => 'Registration Closed'];
    if ($regOpen && $regOpen <= $now && (!$regClose || $regClose >= $now)) return ['key' => 'open',     'label' => 'Registration Open'];
    return ['key' => 'upcoming', 'label' => 'Upcoming'];
  }
}

$status        = getEventStatus($ev, $now);
$isCompleted   = $status['key'] === 'completed';
$isOpen        = $status['key'] === 'open';
$isMembersOnly = ($ev['registrationType'] ?? 'open') === 'members';
$registerLabel = $isMembersOnly ? 'Join Now' : 'Register Now';
$registerIcon  = $isMembersOnly ? 'fa-users' : 'fa-id-card';

$eDate     = !empty($ev['eventDate']) ? new DateTime($ev['eventDate']) : null;
$dateStr   = $eDate ? $eDate->format('l, j F Y') : 'TBA';   // e.g. "Saturday, 14 June 2025"
$dayNum    = $eDate ? $eDate->format('j')         : '--';
$monthAbbr = $eDate ? $eDate->format('M')         : '---';
$yearNum   = $eDate ? $eDate->format('Y')          : '';

$brochureRaw = $ev['brochurePdf'] ?? '';
$brochureHref = '';
if (is_string($brochureRaw) && $brochureRaw !== '') {
  $brochureHref = (str_starts_with($brochureRaw, 'http://') || str_starts_with($brochureRaw, 'https://'))
    ? $brochureRaw
    : asset(ltrim($brochureRaw, '/'));
}
@endphp


<!-- ══════════════════════════════════════════════
     1. HERO
     ══════════════════════════════════════════════ -->
<div class="event-detail-hero">

  @if(!empty($ev['bannerImage']))
  <div class="event-detail-hero__bg">
    <img src="{{ $ev['bannerImage'] }}" alt="{{ $ev['title'] }}" loading="eager">
    <div class="event-detail-hero__overlay"></div>
  </div>
  @else
  <div class="event-detail-hero__bg event-detail-hero__bg--placeholder"></div>
  @endif

  <div class="event-detail-hero__content">

    <nav class="events-breadcrumb" aria-label="Breadcrumb">
      <ol>
        <li><a href="{{ url('/') }}">Home</a></li>
        <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
        <li><a href="{{ url('/events') }}">Events</a></li>
        <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
        <li>{{ $ev['title'] ?? '' }}</li>
      </ol>
    </nav>

    <div class="event-detail-hero__inner">

      <!-- Date badge -->
      <div class="event-detail-date-badge" aria-hidden="true">
        <span class="event-detail-date-badge__month">{{ $monthAbbr }}</span>
        <span class="event-detail-date-badge__day">{{ $dayNum }}</span>
        <span class="event-detail-date-badge__year">{{ $yearNum }}</span>
      </div>

      <div class="event-detail-hero__text">

        <div class="flex flex-wrap items-center gap-2 mb-3">
          <span class="event-card__status event-card__status--{{ $status['key'] }}">
            {{ $status['label'] }}
          </span>
          @if(!empty($ev['category']))
          <span class="event-detail-badge event-detail-badge--category">
            {{ $ev['category'] }}
          </span>
          @endif
          @if(!empty($ev['terrain']) && $ev['terrain'] !== 'N/A')
          <span class="event-detail-badge event-detail-badge--terrain">
            <i class="fas fa-mountain" aria-hidden="true"></i> {{ $ev['terrain'] }}
          </span>
          @endif
        </div>

        <h1 class="font-['Bebas_Neue'] text-5xl md:text-7xl leading-tight text-white" data-reveal>
          {{ $ev['title'] ?? '' }}
        </h1>

        <div class="event-detail-hero__meta mt-4" data-reveal>
          <div class="event-detail-hero__meta-item">
            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
            <span>{{ $dateStr }}@if(!empty($ev['startTime'])) &middot; {{ $ev['startTime'] }}@endif</span>
          </div>
          @if(!empty($ev['location']))
          <div class="event-detail-hero__meta-item">
            <i class="fas fa-map-pin" aria-hidden="true"></i>
            <span>{{ $ev['location'] }}</span>
          </div>
          @endif
          @if(!empty($ev['distance']))
          <div class="event-detail-hero__meta-item">
            <i class="fas fa-route" aria-hidden="true"></i>
            <span>{{ $ev['distance'] }}</span>
          </div>
          @endif
        </div>

        <div class="mt-7 flex flex-wrap gap-3" data-reveal>
          @if($isCompleted)
          <a href="{{ $ev['resultsLink'] ?? '/contact' }}" class="btn btn-primary">
            <i class="fas fa-trophy" aria-hidden="true"></i> View Results
          </a>
          <a href="{{ $ev['photosLink'] ?? '/gallery' }}" class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,0.4)">
            <i class="fas fa-camera" aria-hidden="true"></i> View Photos
          </a>
          @elseif($isOpen)
          @php $regHref = !empty($ev['registrationLink']) ? $ev['registrationLink'] : ($isMembersOnly ? 'https://squidal.com/lfsmembership' : url('/contact')); @endphp
          <a href="{{ $regHref }}" class="btn btn-primary" target="_blank" rel="noopener noreferrer">
            <i class="fas {{ $registerIcon }}" aria-hidden="true"></i> {{ $registerLabel }}
          </a>
          @else
          @php $regHref = !empty($ev['registrationLink']) ? $ev['registrationLink'] : url('/contact'); @endphp
          <a href="{{ $regHref }}" class="btn btn-primary"@if(!empty($ev['registrationLink'])) target="_blank" rel="noopener noreferrer"@endif>
            <i class="fas fa-envelope" aria-hidden="true"></i> Register Interest
          </a>
          @endif
          <a href="{{ url('/events') }}" class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,0.4)">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> All Events
          </a>
          @if($brochureHref !== '')
          <a href="{{ $brochureHref }}"
            class="btn btn-outline event-detail-brochure-cta"
            target="_blank" rel="noopener noreferrer">
            <i class="fas fa-file-pdf" aria-hidden="true"></i> Event brochure
          </a>
          @endif
        </div>

      </div>
    </div><!-- /.inner -->
  </div><!-- /.content -->
</div>


<!-- ══════════════════════════════════════════════
     2. QUICK FACTS STRIP
     ══════════════════════════════════════════════ -->
<div class="event-detail-strip" role="region" aria-label="Event quick facts">
  <div class="event-detail-strip__inner">

    <div class="event-detail-strip__item">
      <i class="fas fa-calendar-alt event-detail-strip__icon" aria-hidden="true"></i>
      <div>
        <div class="event-detail-strip__label">Date</div>
        <div class="event-detail-strip__value">{{ $dateStr }}</div>
      </div>
    </div>

    @if(!empty($ev['startTime']))
    <div class="event-detail-strip__item">
      <i class="fas fa-clock event-detail-strip__icon" aria-hidden="true"></i>
      <div>
        <div class="event-detail-strip__label">Start Time</div>
        <div class="event-detail-strip__value">{{ $ev['startTime'] }}</div>
      </div>
    </div>
    @endif

    @if(!empty($ev['location']))
    <div class="event-detail-strip__item">
      <div>
        <div class="event-detail-strip__label">Location</div>
        <div class="event-detail-strip__value">{{ $ev['location'] }}</div>
      </div>
    </div>
    @endif

    @if(!empty($ev['distance']))
    <div class="event-detail-strip__item">
      <div>
        <div class="event-detail-strip__label">Distance</div>
        <div class="event-detail-strip__value">{{ $ev['distance'] }}</div>
      </div>
    </div>
    @endif

    @if(!empty($ev['difficulty']))
    <div class="event-detail-strip__item">
      <i class="fas fa-gauge-high event-detail-strip__icon" aria-hidden="true"></i>
      <div>
        <div class="event-detail-strip__label">Difficulty</div>
        <div class="event-detail-strip__value">{{ $ev['difficulty'] }}</div>
      </div>
    </div>
    @endif

    @if(!empty($ev['entryFee']))
    <div class="event-detail-strip__item">
      <i class="fas fa-ticket event-detail-strip__icon" aria-hidden="true"></i>
      <div>
        <div class="event-detail-strip__label">Entry Fee</div>
        <div class="event-detail-strip__value">{{ $ev['entryFee'] }}</div>
      </div>
    </div>
    @endif

    @if($brochureHref !== '')
    <div class="event-detail-strip__item">
      <i class="fas fa-file-pdf event-detail-strip__icon" aria-hidden="true"></i>
      <div>
        <div class="event-detail-strip__label">Brochure</div>
        <div class="event-detail-strip__value">
          <a href="{{ $brochureHref }}"
            class="event-detail-brochure-link"
            target="_blank" rel="noopener noreferrer">Download PDF</a>
        </div>
      </div>
    </div>
    @endif

  </div>
</div>


<!-- ══════════════════════════════════════════════
     3. BODY — main content + sidebar
     ══════════════════════════════════════════════ -->
<div class="event-detail-body">
  <div class="event-detail-body__inner">

    <!-- ── LEFT: main content ── -->
    <div class="event-detail-main">

      <!-- Overview -->
      <div class="event-detail-section" data-reveal>
        <span class="section-label">About This Event</span>
        <h2 class="font-['Bebas_Neue'] text-4xl mt-1 mb-4">Overview</h2>
        <p class="text-base text-[#374151] leading-relaxed">{{ $ev['description'] ?? '' }}</p>

        @if(!empty($ev['highlights']) && count($ev['highlights']))
        <ul class="event-detail-highlights mt-5" aria-label="Event highlights">
          @foreach($ev['highlights'] as $h)
          <li class="event-detail-highlights__item">
            <i class="fas fa-check-circle" aria-hidden="true"></i>
            <span>{{ $h }}</span>
          </li>
          @endforeach
        </ul>
        @endif
      </div>

      <!-- Schedule -->
      @if(!empty($ev['schedule']) && count($ev['schedule']))
      <div class="event-detail-section" data-reveal>
        <span class="section-label"><i class="fas fa-list-check" aria-hidden="true"></i> Programme</span>
        <h2 class="font-['Bebas_Neue'] text-4xl mt-1 mb-6">Event Schedule</h2>
        <ol class="event-detail-timeline" aria-label="Event schedule">
          @foreach($ev['schedule'] as $item)
          <li class="event-detail-timeline__item">
            <div class="event-detail-timeline__time">{{ $item['time'] }}</div>
            <div class="event-detail-timeline__dot" aria-hidden="true"></div>
            <div class="event-detail-timeline__content">
              <div class="event-detail-timeline__label">{{ $item['label'] }}</div>
              @if(!empty($item['desc']))
              <div class="event-detail-timeline__desc">{{ $item['desc'] }}</div>
              @endif
            </div>
          </li>
          @endforeach
        </ol>
      </div>
      @endif

      <!-- Key Details -->
      <div class="event-detail-section" data-reveal data-reveal-delay="100">
        <span class="section-label">Key Details</span>
        <h2 class="font-['Bebas_Neue'] text-4xl mt-1 mb-4">Event Details</h2>
        <dl class="event-detail-dl">
          <div class="event-detail-dl__row">
            <dt><i class="fas fa-calendar-alt" aria-hidden="true"></i> Date</dt>
            <dd>{{ $dateStr }}</dd>
          </div>
          @if(!empty($ev['startTime']))
          <div class="event-detail-dl__row">
            <dt><i class="fas fa-clock" aria-hidden="true"></i> Start</dt>
            <dd>{{ $ev['startTime'] }}</dd>
          </div>
          @endif
          @if(!empty($ev['location']))
          <div class="event-detail-dl__row">
            <dt><i class="fas fa-map-pin" aria-hidden="true"></i> Venue</dt>
            <dd>{{ $ev['location'] }}</dd>
          </div>
          @endif
          @if(!empty($ev['distance']))
          <div class="event-detail-dl__row">
            <dt><i class="fas fa-route" aria-hidden="true"></i> Distance</dt>
            <dd>{{ $ev['distance'] }}</dd>
          </div>
          @endif
          @if(!empty($ev['category']))
          <div class="event-detail-dl__row">
            <dt><i class="fas fa-tag" aria-hidden="true"></i> Type</dt>
            <dd>{{ $ev['category'] }}</dd>
          </div>
          @endif
          @if(!empty($ev['terrain']) && $ev['terrain'] !== 'N/A')
          <div class="event-detail-dl__row">
            <dt><i class="fas fa-mountain" aria-hidden="true"></i> Terrain</dt>
            <dd>{{ $ev['terrain'] }}</dd>
          </div>
          @endif
          @if(!empty($ev['difficulty']))
          <div class="event-detail-dl__row">
            <dt><i class="fas fa-gauge-high" aria-hidden="true"></i> Difficulty</dt>
            <dd>{{ $ev['difficulty'] }}</dd>
          </div>
          @endif
        </dl>
      </div>

      <!-- Distance route maps (per distance, from admin) -->
      @if(!empty($ev['distanceRoutes']) && is_array($ev['distanceRoutes']))
      <div class="event-detail-section" data-reveal>
        <span class="section-label"><i class="fas fa-map-location-dot" aria-hidden="true"></i> Route maps</span>
        <h2 class="font-['Bebas_Neue'] text-4xl mt-1 mb-4">Distance routes</h2>
        <div class="event-detail-routes">
          @foreach($ev['distanceRoutes'] as $dr)
            @php
            $dLabel = (string) ($dr['label'] ?? '');
            $dImg   = (string) ($dr['routeImage'] ?? '');
            @endphp
            <figure class="event-detail-route">
              <figcaption class="event-detail-route__label">{{ $dLabel }}</figcaption>
              @if($dImg !== '')
              <div class="event-detail-route__img-wrap event-detail-route__img-wrap--zoomable"
                   data-zoom-src="{{ asset(ltrim($dImg, '/')) }}"
                   data-zoom-alt="Route: {{ $dLabel }}"
                   role="button" tabindex="0"
                   aria-label="Zoom route map: {{ $dLabel }}">
                <img
                  src="{{ asset(ltrim($dImg, '/')) }}"
                  alt="Route: {{ $dLabel }}"
                  class="event-detail-route__img"
                  loading="lazy"
                />
                <div class="event-detail-route__zoom-hint" aria-hidden="true">
                  <i class="fas fa-magnifying-glass-plus"></i>
                </div>
              </div>
              @else
              <p class="event-detail-route__no-img text-sm text-[#6b7280]">Route image will be added soon.</p>
              @endif
            </figure>
          @endforeach
        </div>
      </div>
      @endif

      <!-- Route Info (terrain, difficulty) -->
      @if(!empty($ev['terrain']) || !empty($ev['difficulty']) || !empty($ev['routeDescription']))
      <div class="event-detail-section" data-reveal>
        <span class="section-label"><i class="fas fa-map" aria-hidden="true"></i> Route</span>
        <h2 class="font-['Bebas_Neue'] text-4xl mt-1 mb-4">Route Information</h2>

        <div class="event-detail-route-meta">
          @if(!empty($ev['terrain']) && $ev['terrain'] !== 'N/A')
          <div class="event-detail-route-tag">
            <i class="fas fa-mountain" aria-hidden="true"></i>
            <span><strong>Terrain:</strong> {{ $ev['terrain'] }}</span>
          </div>
          @endif
          @if(!empty($ev['difficulty']))
          <div class="event-detail-route-tag">
            <i class="fas fa-gauge-high" aria-hidden="true"></i>
            <span><strong>Difficulty:</strong> {{ $ev['difficulty'] }}</span>
          </div>
          @endif
          @if(!empty($ev['distance']) && empty($ev['distanceRoutes']))
          <div class="event-detail-route-tag">
            <i class="fas fa-route" aria-hidden="true"></i>
            <span><strong>Distance:</strong> {{ $ev['distance'] }}</span>
          </div>
          @endif
        </div>

        @if(!empty($ev['routeDescription']))
        <p class="mt-4 text-sm text-[#6b7280] leading-relaxed">{{ $ev['routeDescription'] }}</p>
        @endif

        @if(empty($ev['distanceRoutes']))
        <div class="event-detail-map-placeholder mt-5" aria-hidden="true">
          <i class="fas fa-map-location-dot"></i>
          <span>Interactive map coming soon</span>
          <a href="{{ url('/contact') }}" class="btn btn-outline btn-sm mt-3">
            <i class="fas fa-download" aria-hidden="true"></i> Request GPX File
          </a>
        </div>
        @endif
      </div>
      @endif

    </div><!-- /.event-detail-main -->


    <!-- ── RIGHT: sidebar ── -->
    <aside class="event-detail-sidebar">

      <!-- Registration card (upcoming) or results card (completed) -->
      @if(!$isCompleted)
      <div class="event-detail-card event-detail-card--register" data-reveal>
        <div class="event-detail-card__head">
          <span>Registration</span>
        </div>
        <div class="event-detail-card__body">
          <div class="event-detail-reg-status event-detail-reg-status--{{ $status['key'] }}">
            {{ $status['label'] }}
          </div>

          @if(!empty($ev['entryFee']))
          <div class="mt-4">
            <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:rgba(0,0,0,0.4)">Entry Fee</div>
            <div class="event-detail-fee">{{ $ev['entryFee'] }}</div>
          </div>
          @endif

          @if(!empty($ev['maxParticipants']))
          <div class="mt-3">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:rgba(0,0,0,0.4)">Capacity</div>
            <div class="text-sm font-semibold">{{ $ev['maxParticipants'] }} participants max</div>
          </div>
          @endif

          @if(!empty($ev['registrationOpen']) || !empty($ev['registrationClose']))
          <div class="event-detail-reg-dates mt-4">
            @if(!empty($ev['registrationOpen']))
              @php
              $roDate = new DateTime($ev['registrationOpen']);
              $roStr  = $roDate->format('j F Y');
              @endphp
            <div class="event-detail-reg-dates__row">
              <span>Reg. Opens</span>
              <span>{{ $roStr }}</span>
            </div>
            @endif
            @if(!empty($ev['registrationClose']))
              @php
              $rcDate = new DateTime($ev['registrationClose']);
              $rcStr  = $rcDate->format('j F Y');
              @endphp
            <div class="event-detail-reg-dates__row">
              <span>Reg. Closes</span>
              <span>{{ $rcStr }}</span>
            </div>
            @endif
          </div>
          @endif

          @php
            if (!empty($ev['registrationLink'])) {
              $sidebarRegHref   = $ev['registrationLink'];
              $sidebarRegTarget = ' target="_blank" rel="noopener noreferrer"';
            } elseif ($isOpen && $isMembersOnly) {
              $sidebarRegHref   = 'https://squidal.com/lfsmembership';
              $sidebarRegTarget = ' target="_blank" rel="noopener noreferrer"';
            } else {
              $sidebarRegHref   = url('/contact');
              $sidebarRegTarget = '';
            }
          @endphp
          <a href="{{ $sidebarRegHref }}" class="btn btn-primary w-full justify-center mt-5"{!! $sidebarRegTarget !!}>
            @if($isOpen)
            <i class="fas {{ $registerIcon }}" aria-hidden="true"></i> {{ $registerLabel }}
            @else
            <i class="fas fa-envelope" aria-hidden="true"></i> Register Interest
            @endif
          </a>
          <p class="text-xs text-center mt-2 leading-relaxed" style="color:#9ca3af">
            Questions? <a href="{{ url('/contact') }}" style="color:var(--green)">Contact us</a>
          </p>
        </div>
      </div>

      @else

      <div class="event-detail-card" data-reveal>
        <div class="event-detail-card__head">
          <i class="fas fa-trophy" aria-hidden="true"></i>
          <span>Event Results</span>
        </div>
        <div class="event-detail-card__body">
          <p class="text-sm mb-4" style="color:#6b7280">This event has concluded. View the official results and race photos below.</p>
          <a href="{{ $ev['resultsLink'] ?? '/contact' }}" class="btn btn-primary w-full justify-center">
            <i class="fas fa-trophy" aria-hidden="true"></i> Full Results
          </a>
          <a href="{{ $ev['photosLink'] ?? '/gallery' }}" class="btn btn-outline w-full justify-center mt-2">
            <i class="fas fa-camera" aria-hidden="true"></i> Race Photos
          </a>
        </div>
      </div>

      @endif

      <!-- Share -->
      <div class="event-detail-card mt-5" data-reveal data-reveal-delay="150">
        <div class="event-detail-card__head">
          <i class="fas fa-share-nodes" aria-hidden="true"></i>
          <span>Share Event</span>
        </div>
        <div class="event-detail-card__body">
          <div class="event-detail-share">
            @php
            $shareText = ($ev['title'] ?? '') . ' — ' . $dateStr
              . (!empty($ev['location']) ? ' at ' . $ev['location'] : '')
              . '. More info: /events/' . ($ev['slug'] ?? '');
            $shareUrl = 'https://lfs.run/events/' . ($ev['slug'] ?? '');
            @endphp
            <a href="https://wa.me/?text={{ rawurlencode($shareText) }}"
              target="_blank" rel="noopener noreferrer"
              class="event-detail-share__btn event-detail-share__btn--whatsapp"
              aria-label="Share on WhatsApp">
              <i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode($shareUrl) }}"
              target="_blank" rel="noopener noreferrer"
              class="event-detail-share__btn event-detail-share__btn--facebook"
              aria-label="Share on Facebook">
              <i class="fab fa-facebook-f" aria-hidden="true"></i> Facebook
            </a>
            <button type="button"
              class="event-detail-share__btn event-detail-share__btn--copy"
              data-copy-link
              aria-label="Copy link to this event">
              <i class="fas fa-link" aria-hidden="true"></i> Copy Link
            </button>
          </div>
        </div>
      </div>

    </aside>

  </div><!-- /.event-detail-body__inner -->
</div><!-- /.event-detail-body -->


<!-- ══════════════════════════════════════════════
     4. RESULTS (completed events with data)
     ══════════════════════════════════════════════ -->
@if($isCompleted && !empty($ev['results']) && count($ev['results']))
<section class="event-detail-results-section" aria-labelledby="results-heading">
  <div class="event-detail-results-inner">

    <div class="text-center mb-10" data-reveal>
      <span class="section-label justify-center" style="color:var(--green-bright)">
        <i class="fas fa-trophy" aria-hidden="true"></i> Race Results
      </span>
      <h2 class="font-['Bebas_Neue'] text-4xl md:text-5xl mt-2 text-white" id="results-heading">
        Winners &amp; Finishers
      </h2>
    </div>

    <div class="event-detail-results-grid" data-reveal>
      @foreach($ev['results'] as $r)
      <div class="event-detail-result-card">
        <div class="event-detail-result-card__medal" aria-hidden="true">
          <i class="fas fa-medal"></i>
        </div>
        <div class="event-detail-result-card__category">{{ $r['category'] }}</div>
        <div class="event-detail-result-card__winner">{{ $r['winner'] }}</div>
        <div class="event-detail-result-card__time">{{ $r['time'] }}</div>
      </div>
      @endforeach
    </div>

    <div class="text-center mt-8" data-reveal>
      <a href="{{ $ev['resultsLink'] ?? '/contact' }}" class="btn btn-primary">
        <i class="fas fa-list-ol" aria-hidden="true"></i> Full Results List
      </a>
    </div>

  </div>
</section>
@endif


<!-- ══════════════════════════════════════════════
     5. SHOP PREVIEW
     ══════════════════════════════════════════════ -->
@include('partials.shop-preview', ['sectionId' => 'shop', 'bg' => 'var(--off-white)'])


<!-- ══════════════════════════════════════════════
     6. CTA
     ══════════════════════════════════════════════ -->
<section class="py-16 px-6 md:px-16 text-white text-center relative overflow-hidden"
  style="background:var(--dark-green)">
  <div class="absolute font-['Bebas_Neue'] text-[25vw] inset-0 flex items-center justify-center pointer-events-none select-none"
    style="color:rgba(255,255,255,0.04)" aria-hidden="true">RUN</div>
  <div class="relative z-10 max-w-2xl mx-auto" data-reveal>
    <span class="section-label light justify-center">
      <i class="fas fa-person-running" aria-hidden="true"></i> Join The Squad
    </span>
    <h2 class="font-['Bebas_Neue'] text-4xl md:text-6xl text-white mt-3">
      Ready to Race?
    </h2>
    <p class="mt-4 text-white/60 text-base leading-relaxed">
      Every Saturday we run — rain or shine. Join LFS and never run alone.
    </p>
    <div class="flex flex-wrap gap-4 justify-center mt-7">
      <a href="{{ url('/events') }}" class="btn btn-primary">
        <i class="fas fa-calendar-check" aria-hidden="true"></i> All Events
      </a>
      <a href="{{ url('/contact') }}" class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,0.4)">
        <i class="fas fa-envelope" aria-hidden="true"></i> Contact Us
      </a>
    </div>
  </div>
</section>

@endsection
