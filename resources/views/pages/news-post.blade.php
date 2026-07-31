@extends('layouts.app')

@section('content')
@php
/**
 * LFS — Single Blog Post Page
 * /views/news-post.php  (rendered inside main.php layout)
 *
 * Expected variables (from controller):
 *   $post         array  — the blog post
 *   $relatedPosts array  — array of related post arrays (max 3)
 *   $prevPost     array|null
 *   $nextPost     array|null
 */

$post         = $post         ?? [];
$relatedPosts = $relatedPosts ?? [];
$prevPost     = $prevPost     ?? null;
$nextPost     = $nextPost     ?? null;

$catColors = [
  'Club News'            => 'green',
  'Race Results'         => 'orange',
  'Event Announcements'  => 'red',
  'Training Tips'        => 'blue',
  'Member Stories'       => 'gold',
];

if (! function_exists('catClass2')) {
  function catClass2(string $cat): string {
    global $catColors;
    return 'blog-badge--' . ($catColors[$cat] ?? 'green');
  }
}

if (! function_exists('safeStr2')) {
  function safeStr2($v, string $fallback = ''): string {
    return htmlspecialchars((string)($v ?? $fallback), ENT_QUOTES, 'UTF-8');
  }
}

if (! function_exists('postUrl2')) {
  function postUrl2(array $p): string {
    $slug = $p['slug'] ?? $p['id'] ?? 0;
    return url('/news/'.$slug);
  }
}

if (! function_exists('fmtDate2')) {
  function fmtDate2(string $date): string {
    return date('d M Y', strtotime($date));
  }
}

if (! function_exists('excerpt2')) {
  function excerpt2(string $text, int $max = 160): string {
    $plain = strip_tags($text);
    return mb_strlen($plain) > $max ? mb_substr($plain, 0, $max) . '…' : $plain;
  }
}

$shareUrl   = urlencode(rtrim(config('app.url', 'https://www.lfszambia.run'), '/').'/news/'.($post['slug'] ?? ''));
$shareTitle = urlencode($post['title'] ?? 'LFS News');
@endphp

<!-- ══════════════════════════════════════════════════════
     POST HERO BANNER
     ══════════════════════════════════════════════════════ -->
<div class="post-hero">
  @if(!empty($post['image']))
    <div class="post-hero__bg">
      <img src="{{ safeStr2($post['image']) }}" alt="{{ safeStr2($post['title']) }}" class="post-hero__img">
      <div class="post-hero__overlay"></div>
    </div>
  @else
    <div class="post-hero__bg post-hero__bg--placeholder">
      <div class="post-hero__grid" aria-hidden="true"></div>
    </div>
  @endif

  <div class="post-hero__inner">
    <!-- Breadcrumb -->
    <nav class="post-breadcrumb" aria-label="Breadcrumb">
      <a href="{{ url('/') }}">Home</a>
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
      <a href="{{ url('/news') }}">News</a>
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
      <span aria-current="page">{{ safeStr2($post['category'] ?? 'Post') }}</span>
    </nav>

    <!-- Category -->
    <span class="blog-badge blog-badge--lg {{ catClass2($post['category'] ?? '') }}">
      {{ safeStr2($post['category'] ?? 'News') }}
    </span>

    <!-- Title -->
    <h1 class="post-hero__title">{{ safeStr2($post['title'] ?? 'Untitled Post') }}</h1>

    <!-- Meta row -->
    <div class="post-hero__meta">
      @if(!empty($post['author']))
        <span class="post-hero__author">
          <span class="post-hero__author-avatar" aria-hidden="true">
            {{ mb_strtoupper(mb_substr($post['author'], 0, 1)) }}
          </span>
          {{ safeStr2($post['author']) }}
        </span>
        <span class="post-hero__dot" aria-hidden="true">·</span>
      @endif
      <time datetime="{{ safeStr2($post['published_at'] ?? '') }}">
        <i class="far fa-calendar-alt" aria-hidden="true"></i>
        {{ fmtDate2($post['published_at'] ?? $post['date'] ?? 'now') }}
      </time>
      @if(!empty($post['read_time']))
        <span class="post-hero__dot" aria-hidden="true">·</span>
        <span><i class="far fa-clock" aria-hidden="true"></i> {{ (int)$post['read_time'] }} min read</span>
      @endif
    </div>
  </div>
</div>

<!-- Flag stripe under hero -->
<div class="flag-stripe" aria-hidden="true">
  <span></span><span></span><span></span><span></span>
</div>

<!-- ══════════════════════════════════════════════════════
     ARTICLE LAYOUT
     ══════════════════════════════════════════════════════ -->
<div class="post-layout">

  <!-- ── ARTICLE BODY ── -->
  <article class="post-article" id="post-content">

    <!-- Social share — top -->
    <div class="post-share post-share--top">
      <span class="post-share__label">Share</span>
      <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}" target="_blank" rel="noopener" class="post-share__btn post-share__btn--fb" aria-label="Share on Facebook">
        <i class="fab fa-facebook-f"></i>
      </a>
      <a href="https://twitter.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareTitle }}" target="_blank" rel="noopener" class="post-share__btn post-share__btn--tw" aria-label="Share on X / Twitter">
        <i class="fab fa-x-twitter"></i>
      </a>
      <a href="https://wa.me/?text={{ $shareTitle }}%20{{ $shareUrl }}" target="_blank" rel="noopener" class="post-share__btn post-share__btn--wa" aria-label="Share on WhatsApp">
        <i class="fab fa-whatsapp"></i>
      </a>
      <button class="post-share__btn post-share__btn--copy" data-copy-link aria-label="Copy link">
        <i class="fas fa-link"></i>
      </button>
    </div>

    <!-- Post content -->
    <div class="post-content">
      {!! $post['content'] ?? '<p>Content coming soon.</p>' !!}
    </div>

    <!-- Tags / category footer -->
    @if(!empty($post['tags']) || !empty($post['category']))
    <div class="post-tags">
      @if(!empty($post['category']))
        <a href="{{ url('/news') }}?category={{ urlencode($post['category']) }}"
           class="blog-badge {{ catClass2($post['category']) }}">
          {{ safeStr2($post['category']) }}
        </a>
      @endif
      @foreach((array)($post['tags'] ?? []) as $tag)
        <span class="post-tag">#{{ safeStr2($tag) }}</span>
      @endforeach
    </div>
    @endif

    <!-- Social share — bottom -->
    <div class="post-share post-share--bottom">
      <span class="post-share__label">Enjoyed this? Share with the squad:</span>
      <div class="post-share__btns">
        <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}" target="_blank" rel="noopener" class="post-share__btn post-share__btn--fb">
          <i class="fab fa-facebook-f"></i> Facebook
        </a>
        <a href="https://twitter.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareTitle }}" target="_blank" rel="noopener" class="post-share__btn post-share__btn--tw">
          <i class="fab fa-x-twitter"></i> X
        </a>
        <a href="https://wa.me/?text={{ $shareTitle }}%20{{ $shareUrl }}" target="_blank" rel="noopener" class="post-share__btn post-share__btn--wa">
          <i class="fab fa-whatsapp"></i> WhatsApp
        </a>
        <button class="post-share__btn post-share__btn--copy" data-copy-link>
          <i class="fas fa-link"></i> Copy link
        </button>
      </div>
    </div>

    <!-- Prev / Next nav -->
    @if($prevPost || $nextPost)
    <nav class="post-nav" aria-label="Post navigation">
      @if($prevPost)
        <a href="{{ postUrl2($prevPost) }}" class="post-nav__link post-nav__link--prev">
          <span class="post-nav__dir"><i class="fas fa-arrow-left"></i> Previous</span>
          <span class="post-nav__title">{{ safeStr2($prevPost['title']) }}</span>
        </a>
      @else
        <span></span>
      @endif

      @if($nextPost)
        <a href="{{ postUrl2($nextPost) }}" class="post-nav__link post-nav__link--next">
          <span class="post-nav__dir">Next <i class="fas fa-arrow-right"></i></span>
          <span class="post-nav__title">{{ safeStr2($nextPost['title']) }}</span>
        </a>
      @endif
    </nav>
    @endif

  </article>

  <!-- ── SIDEBAR ── -->
  <aside class="post-sidebar" aria-label="Sidebar">

    <!-- About LFS card -->
    <div class="sidebar-card sidebar-card--dark">
      <div class="sidebar-card__header">
        <i class="fas fa-users sidebar-card__icon"></i>
        About LFS
      </div>
      <p class="sidebar-card__body">Zambia's biggest fitness community. We train, race, and celebrate together since 2017.</p>
      <a href="{{ url('/about') }}" class="btn btn-primary btn-sm w-full mt-3">Learn More</a>
    </div>

    <!-- Categories -->
    <div class="sidebar-card">
      <div class="sidebar-card__header">
        <i class="fas fa-tags sidebar-card__icon text-green"></i>
        Categories
      </div>
      <ul class="sidebar-cats">
        @php $allCats = ['Club News','Race Results','Event Announcements','Training Tips','Member Stories']; @endphp
        @foreach($allCats as $cat)
          @php $active = ($post['category'] ?? '') === $cat; @endphp
          <li>
            <a href="{{ url('/news') }}?category={{ urlencode($cat) }}"
               class="sidebar-cat{{ $active ? ' sidebar-cat--active' : '' }}">
              <span class="sidebar-cat__dot blog-badge--{{ $catColors[$cat] ?? 'green' }}"></span>
              {{ safeStr2($cat) }}
              <i class="fas fa-arrow-right sidebar-cat__arrow"></i>
            </a>
          </li>
        @endforeach
      </ul>
    </div>

    <!-- Follow / socials -->
    <div class="sidebar-card sidebar-card--dark">
      <div class="sidebar-card__header">
        <i class="fas fa-share-alt sidebar-card__icon"></i>
        Follow the Squad
      </div>
      <div class="sidebar-socials">
        <a href="https://facebook.com/lfszambia" target="_blank" rel="noopener" class="sidebar-social sidebar-social--fb">
          <i class="fab fa-facebook-f"></i> Facebook
        </a>
        <a href="https://instagram.com/lfszambia" target="_blank" rel="noopener" class="sidebar-social sidebar-social--ig">
          <i class="fab fa-instagram"></i> Instagram
        </a>
        <a href="https://wa.me/260966755326" target="_blank" rel="noopener" class="sidebar-social sidebar-social--wa">
          <i class="fab fa-whatsapp"></i> WhatsApp
        </a>
        <a href="https://twitter.com/lfszambia" target="_blank" rel="noopener" class="sidebar-social sidebar-social--tw">
          <i class="fab fa-x-twitter"></i> X / Twitter
        </a>
      </div>
    </div>

    <!-- Join CTA -->
    <div class="sidebar-card sidebar-card--cta">
      <p class="sidebar-card__eyebrow">Ready to run?</p>
      <h3 class="sidebar-card__cta-title">Join LFS Today</h3>
      <p class="sidebar-card__body">Become part of Zambia's biggest running community and never run alone.</p>
      <a href="https://squidal.com/lfsmembership" target="_blank" rel="noopener noreferrer" class="btn btn-orange w-full mt-3">
        <i class="fas fa-running"></i> Join Now
      </a>
    </div>

  </aside>

</div><!-- /.post-layout -->

<!-- ══════════════════════════════════════════════════════
     RELATED POSTS
     ══════════════════════════════════════════════════════ -->
@if(!empty($relatedPosts))
<section class="related-posts" aria-labelledby="related-heading">
  <div class="related-posts__header">
    <div class="flag-stripe mb-4" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
    <h2 class="related-posts__title" id="related-heading">More from the Squad</h2>
  </div>
  <div class="related-posts__grid">
    @foreach(array_slice($relatedPosts, 0, 3) as $rp)
      <article class="blog-card">
        <a href="{{ postUrl2($rp) }}" class="blog-card__img-link" tabindex="-1" aria-hidden="true">
          <div class="blog-card__img-wrap">
            @if(!empty($rp['image']))
              <img src="{{ safeStr2($rp['image']) }}" alt="{{ safeStr2($rp['title']) }}" loading="lazy">
            @else
              <div class="blog-card__img-placeholder"><i class="fas fa-running" aria-hidden="true"></i></div>
            @endif
            <span class="blog-badge {{ catClass2($rp['category'] ?? '') }}">{{ safeStr2($rp['category'] ?? 'News') }}</span>
          </div>
        </a>
        <div class="blog-card__body">
          <div class="blog-card__meta">
            <time class="blog-meta-date" datetime="{{ safeStr2($rp['published_at'] ?? '') }}">
              <i class="far fa-calendar-alt" aria-hidden="true"></i>
              {{ fmtDate2($rp['published_at'] ?? $rp['date'] ?? 'now') }}
            </time>
          </div>
          <h3 class="blog-card__title"><a href="{{ postUrl2($rp) }}">{{ safeStr2($rp['title']) }}</a></h3>
          <p class="blog-card__excerpt">{{ safeStr2(excerpt2($rp['excerpt'] ?? $rp['content'] ?? '', 130)) }}</p>
          <a href="{{ postUrl2($rp) }}" class="blog-card__read-more">Read More <i class="fas fa-arrow-right"></i></a>
        </div>
      </article>
    @endforeach
  </div>
  <div class="related-posts__footer">
    <a href="{{ url('/news') }}" class="btn btn-outline">
      <i class="fas fa-newspaper"></i> All News &amp; Updates
    </a>
  </div>
</section>
@endif

@endsection
