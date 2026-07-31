@extends('layouts.app')

@section('content')
@php
  $alb       = $album ?? [];
  $aDate     = !empty($alb['date']) ? new DateTime($alb['date']) : null;
  $dateStr   = $aDate ? $aDate->format('j F Y') : null;   // e.g. "21 February 2026"
  $itemCount = is_array($media ?? null) ? count($media) : (int)($alb['mediaCount'] ?? 0);
@endphp

<!-- ══════════════════════════════════════════════
     1. HERO
     ══════════════════════════════════════════════ -->
<div class="event-detail-hero">

  @if(!empty($alb['coverImage']))
  <div class="event-detail-hero__bg">
    <img src="{{ $alb['coverImage'] }}"
         alt="{{ $alb['title'] ?? '' }}"
         loading="eager">
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
        <li><a href="{{ url('/gallery') }}">Gallery</a></li>
        <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
        <li>{{ $alb['title'] ?? '' }}</li>
      </ol>
    </nav>

    <div class="event-detail-hero__inner">
      <div class="event-detail-hero__text">
        <h1 class="font-['Bebas_Neue'] text-5xl md:text-7xl leading-tight text-white">
          {{ $alb['title'] ?? '' }}
        </h1>

        <div class="event-detail-hero__meta mt-4">
          @if($dateStr)
          <div class="event-detail-hero__meta-item">
            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
            <span>{{ $dateStr }}</span>
          </div>
          @endif
          @if(!empty($alb['location']))
          <div class="event-detail-hero__meta-item">
            <i class="fas fa-map-pin" aria-hidden="true"></i>
            <span>{{ $alb['location'] }}</span>
          </div>
          @endif
          @if(!empty($alb['category']))
          <div class="event-detail-hero__meta-item">
            <i class="fas fa-tag" aria-hidden="true"></i>
            <span>{{ $alb['category'] }}</span>
          </div>
          @endif
          @if($itemCount > 0)
          <div class="event-detail-hero__meta-item">
            <i class="fas fa-images" aria-hidden="true"></i>
            <span>{{ $itemCount }} item{{ $itemCount === 1 ? '' : 's' }}</span>
          </div>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     2. BODY — media grid
     ══════════════════════════════════════════════ -->
<div class="event-detail-body">
  <div class="event-detail-body__inner">
    <div class="event-detail-main">

      @if(!empty($media))
        <div class="gallery-grid" role="list" aria-label="Album photos and videos">
          @foreach($media as $idx => $item)
            @php
            $imgUrl = $item['urls']['medium']
                   ?? $item['urls']['thumbnail']
                   ?? $item['urls']['large']
                   ?? null;
            $isVideo   = ($item['type'] ?? '') === 'video';
            $caption   = htmlspecialchars($item['caption'] ?? '', ENT_QUOTES, 'UTF-8');
            $ariaLabel = 'View ' . ($item['caption'] ? $caption : ($isVideo ? 'video' : 'photo')) . ' ' . ($idx + 1);

            $sizeClass   = '';
            $ratioSource = $imgUrl ?? ($item['urls']['large'] ?? $item['urls']['original'] ?? null);
            if ($ratioSource && str_starts_with($ratioSource, '/')) {
              $fsPath = public_path(ltrim($ratioSource, '/'));
              if (is_file($fsPath)) {
                [$w, $h] = @getimagesize($fsPath) ?: [0, 0];
                if ($w > 0 && $h > 0) {
                  $ratio = $w / $h;
                  if ($ratio >= 1.5) {
                    $sizeClass = 'gallery-grid__item--wide';
                  } elseif ($ratio <= 0.8) {
                    $sizeClass = 'gallery-grid__item--tall';
                  }
                }
              }
            }
            @endphp
            <div class="gallery-grid__item gallery-grid__item--clickable {{ $sizeClass }}" role="listitem"
                 onclick="openLightbox({{ $idx }})" tabindex="0"
                 onkeydown="if(event.key==='Enter'||event.key===' ')openLightbox({{ $idx }})"
                 aria-label="{{ $ariaLabel }}">

              @if($isVideo)
                @if($imgUrl)
                  <img src="{{ $imgUrl }}"
                       alt="{{ $caption ?: 'Video thumbnail' }}"
                       loading="lazy">
                @else
                  <div class="w-full h-full flex items-center justify-center"
                       style="background:var(--black-mid); color:var(--white-dim);">
                    <i class="fas fa-play" style="font-size:2rem;"></i>
                  </div>
                @endif
                <div class="gallery-grid__play-badge"><i class="fas fa-play"></i></div>
              @else
                @if($imgUrl)
                  <img src="{{ $imgUrl }}"
                       alt="{{ $caption }}"
                       loading="lazy">
                @else
                  <div class="w-full h-full flex items-center justify-center"
                       style="background:var(--black-mid); color:var(--white-dim);">
                    <i class="fas fa-image" style="font-size:2rem;"></i>
                  </div>
                @endif
              @endif

            </div>
          @endforeach
        </div>

      @else
        <p class="text-sm text-[#6b7280]">
          No photos or videos have been added to this album yet.
        </p>
      @endif

    </div><!-- /.event-detail-main -->
  </div><!-- /.event-detail-body__inner -->
</div><!-- /.event-detail-body -->


<!-- ══════════════════════════════════════════════
     LIGHTBOX
     ══════════════════════════════════════════════ -->
<div id="lbOverlay" class="lb-overlay is-hidden" role="dialog" aria-modal="true" aria-label="Image preview">
  <button class="lb-close" onclick="closeLightbox()" aria-label="Close"><i class="fas fa-xmark"></i></button>
  <button class="lb-nav lb-nav--prev" onclick="lbPrev()" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
  <button class="lb-nav lb-nav--next" onclick="lbNext()" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
  <div class="lb-content" id="lbContent"></div>
  <div class="lb-meta" id="lbMeta">
    <span class="lb-counter" id="lbCounter"></span>
    <span class="lb-caption" id="lbCaption"></span>
  </div>
</div>

@endsection
