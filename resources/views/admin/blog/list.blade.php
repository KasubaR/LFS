@extends('layouts.admin')

@section('content')
@php
$posts          = $posts          ?? [];
$categories     = $categories     ?? [];
$filterStatus   = $filterStatus   ?? '';
$filterCategory = $filterCategory ?? '';
$filterSearch   = $filterSearch   ?? '';

$publishedCount = 0;
$draftCount     = 0;
$featuredCount  = 0;
foreach ($posts as $p) {
    if (($p['status'] ?? '') === 'published') $publishedCount++;
    else $draftCount++;
    if (!empty($p['featured'])) $featuredCount++;
}
@endphp

@if(!empty($postsError))
  <div class="gallery-error-banner" role="alert" style="margin-bottom:1rem;">
    <i class="fas fa-triangle-exclamation"></i>
    <span>{{ $postsError }}</span>
  </div>
@endif

<!-- ══════════════════════════════════════════════
     STATS
════════════════════════════════════════════════ -->
<section class="stats-grid" style="grid-template-columns:repeat(3,1fr); margin-bottom:1.5rem;" aria-label="Blog stats">
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-circle-check"></i></div>
    <p class="stat-card__label">Published</p>
    <p class="stat-card__value">{{ $publishedCount }}</p>
  </article>
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-file-pen"></i></div>
    <p class="stat-card__label">Drafts</p>
    <p class="stat-card__value">{{ $draftCount }}</p>
  </article>
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-star"></i></div>
    <p class="stat-card__label">Featured</p>
    <p class="stat-card__value">{{ $featuredCount }}</p>
  </article>
</section>

<!-- ══════════════════════════════════════════════
     TOOLBAR
════════════════════════════════════════════════ -->
<div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">

  <form method="GET" action="/admin/blog/list"
        style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">

    <!-- Status filter -->
    <select name="status"
            style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem;">
      <option value="">All Statuses</option>
      <option value="published" {{ $filterStatus === 'published' ? 'selected' : '' }}>Published</option>
      <option value="draft"     {{ $filterStatus === 'draft'     ? 'selected' : '' }}>Draft</option>
    </select>

    <!-- Category filter -->
    <select name="category"
            style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem;">
      <option value="">All Categories</option>
      @foreach($categories as $c)
        <option value="{{ $c }}" {{ $filterCategory === $c ? 'selected' : '' }}>
          {{ $c }}
        </option>
      @endforeach
    </select>

    <!-- Search -->
    <input type="search" name="search"
           value="{{ $filterSearch }}"
           placeholder="Search posts…"
           style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem; min-width:180px;" />

    <button type="submit"
            style="padding:0.55rem 1rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.85rem; cursor:pointer;">
      Filter
    </button>
    <a href="/admin/blog/list"
       style="padding:0.55rem 0.8rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); font-size:0.85rem; text-decoration:none;">
      <i class="fas fa-xmark"></i> Clear
    </a>
  </form>

  <a href="/admin/blog/create"
     style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.6rem 1.1rem; background:var(--flag-green); color:#fff; border-radius:8px; font-family:var(--font-body); font-size:0.85rem; font-weight:600; text-decoration:none; white-space:nowrap; margin-left:auto;">
    <i class="fas fa-plus"></i> New Post
  </a>
</div>

<!-- ══════════════════════════════════════════════
     POSTS TABLE
════════════════════════════════════════════════ -->
@if(!empty($posts))
  <div style="overflow-x:auto;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-mid); text-align:left;">
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Title</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Status</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Category</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Author</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Views</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Published</th>
          <th style="padding:0.75rem; font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($posts as $p)
          <tr style="border-bottom:1px solid var(--border-subtle);">

            <!-- Title + featured badge -->
            <td style="padding:0.75rem; max-width:300px;">
              <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                @if(!empty($p['featured']))
                  <span style="font-size:0.7rem; background:rgba(224,123,57,0.15); color:var(--flag-orange,#e07b39); border:1px solid rgba(224,123,57,0.3); border-radius:4px; padding:0.15rem 0.45rem; white-space:nowrap;">
                    ★ Featured
                  </span>
                @endif
                <span style="color:var(--off-white); font-size:0.9rem; font-weight:500;">
                  {{ $p['title'] }}
                </span>
              </div>
              <div style="font-size:0.75rem; color:var(--text-dim); margin-top:0.2rem;">
                /blog/{{ $p['slug'] }}
              </div>
            </td>

            <!-- Status badge -->
            <td style="padding:0.75rem;">
              @if(($p['status'] ?? '') === 'published')
                <span style="display:inline-block; padding:0.2rem 0.6rem; background:rgba(74,124,89,0.2); color:var(--green-bright,#7ecb93); border:1px solid rgba(74,124,89,0.4); border-radius:20px; font-size:0.75rem; font-weight:600; white-space:nowrap;">
                  Published
                </span>
              @else
                <span style="display:inline-block; padding:0.2rem 0.6rem; background:rgba(255,255,255,0.06); color:var(--text-dim); border:1px solid var(--border-mid); border-radius:20px; font-size:0.75rem; font-weight:600; white-space:nowrap;">
                  Draft
                </span>
              @endif
            </td>

            <td style="padding:0.75rem; color:var(--off-white); font-size:0.88rem;">{{ $p['category'] ?? '—' }}</td>
            <td style="padding:0.75rem; color:var(--text-dim); font-size:0.88rem;">{{ $p['author'] ?? '—' }}</td>
            <td style="padding:0.75rem; color:var(--text-dim); font-size:0.88rem;">{{ number_format((int)($p['views'] ?? 0)) }}</td>
            <td style="padding:0.75rem; color:var(--text-dim); font-size:0.88rem;">{{ blogFormatDate($p['publishDate'] ?? null) }}</td>

            <!-- Actions -->
            <td style="padding:0.75rem;">
              <div class="upcoming-events__actions">
                <a href="/admin/blog/{{ $p['id'] }}/edit"
                   class="admin-btn admin-btn--primary admin-btn--sm">
                  <i class="fas fa-pen" aria-hidden="true"></i> Edit
                </a>
                <a href="/admin/blog/{{ $p['id'] }}/delete"
                   class="admin-btn admin-btn--danger admin-btn--sm">
                  <i class="fas fa-trash" aria-hidden="true"></i> Delete
                </a>
              </div>
            </td>

          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@else
  <div style="text-align:center; padding:4rem 2rem; color:var(--text-dim);">
    <i class="fas fa-pencil" style="font-size:3rem; margin-bottom:1rem; display:block; opacity:0.4;"></i>
    <p style="font-size:1.1rem; margin-bottom:0.5rem;">No posts yet</p>
    <p style="font-size:0.85rem; margin-bottom:1.5rem;">Create your first blog post to share news, race reports, and training tips.</p>
    <a href="/admin/blog/create"
       style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.65rem 1.25rem; background:var(--flag-green); color:#fff; border-radius:8px; text-decoration:none; font-weight:600;">
      <i class="fas fa-plus"></i> New Post
    </a>
  </div>
@endif

@endsection
