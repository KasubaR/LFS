@extends('layouts.app')

@section('content')
@php
/**
 * Format an album date like "21st February, 2026".
 */
if (!function_exists('lfs_format_album_date_long')) {
  function lfs_format_album_date_long(?string $date): ?string {
    if (empty($date)) return null;
    try {
      $dt = new DateTime($date);
    } catch (Throwable) {
      return null;
    }
    $day    = (int) $dt->format('j');
    $mod100 = $day % 100;
    if ($mod100 >= 11 && $mod100 <= 13) {
      $suffix = 'th';
    } else {
      $suffix = match ($day % 10) {
        1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
      };
    }
    return $day . $suffix . ' ' . $dt->format('F, Y');
  }
}

$allAlbums   = $albums ?? [];
$totalAlbums = count($allAlbums);

$totalPhotos = 0;
foreach ($allAlbums as $a) $totalPhotos += (int)($a['mediaCount'] ?? 0);
@endphp


<!-- ══════════════════════════════════════════════
     1. PAGE HEADER
     ══════════════════════════════════════════════ -->
@php $hasBanner = !empty($galleryBanner ?? null); @endphp
<header class="gallery-header{{ $hasBanner ? ' gallery-header--has-banner' : '' }}"
  @if($hasBanner) style="background-image:url('{{ e($galleryBanner) }}')" @endif>
  @if($hasBanner)
  <div class="gallery-header__overlay" aria-hidden="true"></div>
  @endif
  <div class="gallery-header__inner">

    <nav class="gallery-breadcrumb" aria-label="Breadcrumb">
      <ol>
        <li><a href="{{ url('/') }}">Home</a></li>
        <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
        <li>Gallery</li>
      </ol>
    </nav>

    <span class="section-label light" data-reveal>
      <i class="fas fa-camera" aria-hidden="true"></i> LFS Gallery
    </span>
    <h1 class="font-['Bebas_Neue'] text-5xl md:text-6xl leading-tight text-white mt-2" data-reveal>
      Photo Albums
    </h1>
    <p class="gallery-header__desc" data-reveal>
      Photos and videos from LFS runs, races and community events.
    </p>

    <!-- Quick stats -->
    <div class="stat-row mt-8" data-reveal>
      <div class="stat-item">
        <div class="stat-item__num">{{ $totalAlbums }}</div>
        <div class="stat-item__label">Albums</div>
      </div>
      @if($totalPhotos > 0)
      <div class="stat-item">
        <div class="stat-item__num">{{ $totalPhotos }}+</div>
        <div class="stat-item__label">Photos</div>
      </div>
      @endif
    </div>

  </div>
</header>


<!-- ══════════════════════════════════════════════
     3. GALLERY BODY
     ══════════════════════════════════════════════ -->
<div class="gallery-body">
  <div class="gallery-body__inner">

    <section class="gallery-section" id="albums" aria-label="Gallery albums">

      <div class="gallery-section__heading">
        <div>
          <span class="section-label"><i class="fas fa-images" aria-hidden="true"></i> All Albums</span>
        </div>
      </div>

      @if(!empty($galleryError))
      <div class="gallery-public-error" role="alert">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <span>{{ $galleryError }}</span>
      </div>
      @endif

      @if(!empty($allAlbums))

        <div class="gallery-albums-public" role="list" aria-label="Gallery albums">
          @foreach($allAlbums as $album)
            @php
            $hasExternal   = !empty(trim($album['externalUrl'] ?? ''));
            $href          = $hasExternal ? $album['externalUrl'] : url('/gallery/'.$album['_id']);
            $formattedDate = lfs_format_album_date_long($album['date'] ?? null);
            $mc            = isset($album['mediaCount']) ? (int)$album['mediaCount'] : 0;
            @endphp
            <a href="{{ $href }}"
               class="gallery-album-card"
               role="listitem"
               @if($hasExternal) target="_blank" rel="noopener" @endif>

              <div class="gallery-album-card__cover">
                @if(!empty($album['coverImage']))
                  <img src="{{ $album['coverImage'] }}"
                       alt="{{ $album['title'] }}"
                       loading="lazy">
                @else
                  <div class="gallery-album-card__placeholder" aria-hidden="true">
                    <i class="fas fa-images"></i>
                    <span>LFS</span>
                  </div>
                @endif

                @if($mc > 0)
                <span class="gallery-album-card__badge">
                  <i class="fas fa-camera" aria-hidden="true"></i> {{ $mc }}
                </span>
                @endif


              </div>

              <div class="gallery-album-card__body">
                <h2 class="gallery-album-card__title">{{ $album['title'] }}</h2>
                @if(!empty($formattedDate))
                <div class="gallery-album-card__meta-row">
                  <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                  <span>{{ $formattedDate }}</span>
                </div>
                @endif
              </div>

              <div class="gallery-album-card__footer">
                View Album <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </div>

            </a>
          @endforeach
        </div>

      @elseif(!empty($fallbackMedia))
        <p class="gallery-empty-text mb-8">Albums coming soon — here's a sneak peek from our latest run.</p>
        <div class="gallery-grid" role="list" aria-label="LFS photo gallery">
          @foreach($fallbackMedia as $i => $photo)
            @php
            $sizeClass = match ($i) {
                0 => 'gallery-grid__item--tall',
                1 => 'gallery-grid__item--wide',
                default => '',
            };
            @endphp
            <div class="gallery-grid__item {{ $sizeClass }}" role="listitem">
              <img src="{{ $photo['src'] }}"
                   alt="{{ $photo['alt'] ?? '' }}"
                   loading="lazy">
            </div>
          @endforeach
        </div>

      @else
        <div class="events-empty">
          <div class="events-empty__icon"><i class="fas fa-camera" aria-hidden="true"></i></div>
          <div class="events-empty__heading">No Albums Yet</div>
          <p class="events-empty__desc">Photos from our runs and events will appear here. Check back soon.</p>
        </div>
      @endif

    </section>

  </div>
</div><!-- /.gallery-body -->


<!-- ══════════════════════════════════════════════
     4. CTA
     ══════════════════════════════════════════════ -->
<section class="py-16 px-6 md:px-16 text-white text-center relative overflow-hidden"
  style="background:var(--dark-green)">
  <div class="absolute font-['Bebas_Neue'] text-[25vw] inset-0 flex items-center justify-center pointer-events-none select-none"
    style="color:rgba(255,255,255,0.04)" aria-hidden="true">RUN</div>
  <div class="relative z-10 max-w-2xl mx-auto" data-reveal>
    <span class="section-label light justify-center">
      <i class="fas fa-running" aria-hidden="true"></i> Join The Squad
    </span>
    <h2 class="font-['Bebas_Neue'] text-4xl md:text-6xl text-white mt-3">
      Run With Us
    </h2>
    <p class="mt-4 text-white/60 text-base leading-relaxed">
      Every Saturday we run — rain or shine. Join LFS and be part of the story.
    </p>
    <div class="flex flex-wrap gap-4 justify-center mt-7">
      <a href="{{ url('/events') }}" class="btn btn-primary">
        <i class="fas fa-calendar-check" aria-hidden="true"></i> View Events
      </a>
      <a href="{{ url('/contact') }}" class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,0.4)">
        <i class="fas fa-envelope" aria-hidden="true"></i> Contact Us
      </a>
    </div>
  </div>
</section>

@endsection
