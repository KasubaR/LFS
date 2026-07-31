@extends('layouts.admin')

@section('content')
@php
$pageTitle      = 'Gallery — Albums';
$activePage     = 'gallery';
$breadcrumbs    = [
    ['label' => 'Gallery', 'url' => '/admin/gallery'],
    ['label' => 'Albums'],
];

$albums         = $albums         ?? [];
$stats          = $stats          ?? [];
$categories     = $categories     ?? ['Race', 'Training', 'LSD', 'Social'];
$filterYear     = $filterYear     ?? '';
$filterCategory = $filterCategory ?? '';
$searchQuery    = $searchQuery    ?? '';
$currentYear    = (int) date('Y');
@endphp

@if(!empty($galleryError))
  <div class="gallery-error-banner" role="alert">
    <i class="fas fa-triangle-exclamation"></i>
    <span>{{ $galleryError }}</span>
  </div>
@endif

<!-- ══════════════════════════════════════════════
     STATS ROW
════════════════════════════════════════════════ -->
<section class="stats-grid" style="grid-template-columns:repeat(3,1fr); margin-bottom:1.5rem;" aria-label="Gallery stats">

  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-folder-open"></i></div>
    <p class="stat-card__label">Total Albums</p>
    <p class="stat-card__value">{{ isset($stats['totalAlbums']) ? $stats['totalAlbums'] : '—' }}</p>
  </article>

  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-images"></i></div>
    <p class="stat-card__label">Total Media</p>
    <p class="stat-card__value">{{ isset($stats['totalMedia']) ? $stats['totalMedia'] : '—' }}</p>
  </article>

  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-star"></i></div>
    <p class="stat-card__label">Featured</p>
    <p class="stat-card__value">{{ isset($stats['featuredCount']) ? $stats['featuredCount'] : '—' }}</p>
  </article>

</section>

<!-- ══════════════════════════════════════════════
     TOOLBAR
════════════════════════════════════════════════ -->
<div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">

  <form method="GET" action="/admin/gallery/albums" style="display:flex; gap:0.5rem; flex:1; min-width:200px;">

    <!-- Search -->
    <div style="position:relative; flex:1;">
      <i class="fas fa-magnifying-glass" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); color:var(--text-dim); font-size:0.8rem;"></i>
      <input type="text" name="search"
             value="{{ $searchQuery }}"
             placeholder="Search albums or tags…"
             style="width:100%; padding:0.55rem 0.75rem 0.55rem 2.25rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem;" />
    </div>

    <!-- Category filter -->
    <select name="category"
            style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem;">
      <option value="">All Categories</option>
      @foreach($categories as $c)
        <option value="{{ $c }}"
          {{ $filterCategory === $c ? 'selected' : '' }}>
          {{ $c }}
        </option>
      @endforeach
    </select>

    <!-- Year filter -->
    <select name="year"
            style="padding:0.55rem 0.75rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.85rem;">
      <option value="">All Years</option>
      @for($y = $currentYear; $y >= $currentYear - 5; $y--)
        <option value="{{ $y }}"
          {{ ((int)$filterYear === $y) ? 'selected' : '' }}>
          {{ $y }}
        </option>
      @endfor
    </select>

    <button type="submit"
            style="padding:0.55rem 1rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.85rem; cursor:pointer;">
      Filter
    </button>
    <a href="/admin/gallery/albums"
       style="padding:0.55rem 0.8rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:0.35rem;">
      <i class="fas fa-xmark"></i> Clear
    </a>
  </form>

  <!-- Create Album -->
  <a href="/admin/gallery/albums/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus"></i> New Album
  </a>
  <a href="/admin/gallery/settings" class="admin-btn admin-btn--ghost">
    <i class="fas fa-image"></i> Gallery Settings
  </a>

</div>

<!-- ══════════════════════════════════════════════
     ALBUMS GRID
════════════════════════════════════════════════ -->
@if(!empty($albums))

  <div class="gallery-albums-grid" id="albumsGrid">
    @foreach($albums as $album)
      @php
        $catSlug = strtolower(preg_replace('/\s+/', '-', $album['category'] ?? 'other'));
        $albumId = htmlspecialchars($album['id']);
      @endphp

      <article class="album-card" data-id="{{ $albumId }}">

        <!-- Cover image -->
        <a href="/admin/gallery/albums/{{ $albumId }}/manage" class="album-card__cover">
          @if(!empty($album['coverImage']))
            <img src="{{ $album['coverImage'] }}"
                 alt="{{ $album['title'] }} cover"
                 loading="lazy"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
            <div class="album-card__cover-placeholder" style="display:none;">
              <i class="fas fa-images"></i>
            </div>
          @else
            <div class="album-card__cover-placeholder">
              <i class="fas fa-images"></i>
            </div>
          @endif

          <!-- Badges overlay -->
          <div class="album-card__badges">
            @if(!empty($album['featured']))
              <span class="badge badge--gold"><i class="fas fa-star"></i> Featured</span>
            @endif
            <span class="badge badge--dark">{{ (int)($album['mediaCount'] ?? 0) }} items</span>
          </div>

          <!-- Category chip -->
          <span class="album-card__category album-cat--{{ $catSlug }}">
            {{ $album['category'] ?? 'General' }}
          </span>
        </a>

        <!-- Info -->
        <div class="album-card__body">
          <h3 class="album-card__title">
            <a href="/admin/gallery/albums/{{ $albumId }}/manage">
              {{ $album['title'] }}
            </a>
          </h3>

          <div class="album-card__meta">
            @if(!empty($album['date']))
              <span>
                <i class="fas fa-calendar" aria-hidden="true"></i>
                {{ date('j M Y', strtotime($album['date'])) }}
              </span>
            @endif
            @if(!empty($album['location']))
              <span>
                <i class="fas fa-location-dot" aria-hidden="true"></i>
                {{ $album['location'] }}
              </span>
            @endif
          </div>

          @if(!empty($album['tags']))
            <div class="album-card__tags">
              @foreach(array_slice((array)$album['tags'], 0, 4) as $tag)
                <span class="tag">#{{ $tag }}</span>
              @endforeach
            </div>
          @endif

          <!-- Actions -->
          <div class="album-card__actions">
            <a href="/admin/gallery/albums/{{ $albumId }}/manage"
               class="btn-sm btn-sm--ghost" title="Manage media">
              <i class="fas fa-images"></i> Manage
            </a>
            <a href="/admin/gallery/upload?album={{ $albumId }}"
               class="btn-sm btn-sm--ghost" title="Upload to album">
              <i class="fas fa-upload"></i> Upload
            </a>
            <a href="/admin/gallery/albums/{{ $albumId }}/edit"
               class="btn-sm btn-sm--ghost" title="Edit album">
              <i class="fas fa-pen"></i> Edit
            </a>
            <button type="button"
                    class="btn-sm btn-sm--danger"
                    data-album-title="{{ $album['title'] }}"
                    onclick="confirmDeleteAlbum('{{ $albumId }}', this.dataset.albumTitle)"
                    title="Delete album">
              <i class="fas fa-trash"></i> Delete
            </button>
          </div>
        </div>

      </article>
    @endforeach
  </div>

@else

  <!-- Empty state -->
  <div style="text-align:center; padding:4rem 2rem; color:var(--text-dim);">
    <i class="fas fa-folder-open" style="font-size:3rem; margin-bottom:1rem; display:block; opacity:0.4;"></i>
    <p style="font-size:1.1rem; margin-bottom:0.5rem;">No albums yet</p>
    <p style="font-size:0.85rem; margin-bottom:1.5rem;">Create your first album to start organising the LFS media library.</p>
    <a href="/admin/gallery/albums/create"
       style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.65rem 1.25rem; background:var(--flag-green); color:#fff; border-radius:8px; text-decoration:none; font-weight:600;">
      <i class="fas fa-plus"></i> Create First Album
    </a>
  </div>

@endif


<!-- ══════════════════════════════════════════════
     DELETE CONFIRMATION MODAL
════════════════════════════════════════════════ -->
<div id="deleteAlbumModal" class="lfs-modal-overlay" style="display:none;"
     role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
  <div class="lfs-modal">
    <div class="lfs-modal__header">
      <h2 id="deleteModalTitle">
        <i class="fas fa-triangle-exclamation" style="color:var(--flag-red);"></i> Delete Album
      </h2>
    </div>
    <div class="lfs-modal__body">
      <p>Are you sure you want to delete <strong id="deleteAlbumName"></strong>?</p>
      <p style="margin-top:0.5rem; color:var(--flag-red); font-size:0.85rem;">
        <i class="fas fa-exclamation-circle"></i>
        This will permanently delete all media in this album. This action cannot be undone.
      </p>
    </div>
    <div class="lfs-modal__footer">
      <button onclick="closeDeleteModal()" class="btn-sm btn-sm--ghost">Cancel</button>
      <form id="deleteAlbumForm" method="POST" action="" style="display:inline;">
        <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">
        <button type="submit" class="btn-sm btn-sm--danger">
          <i class="fas fa-trash"></i> Delete Album
        </button>
      </form>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     STYLES
════════════════════════════════════════════════ -->
<style>
.gallery-albums-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1.25rem;
}

/* Album card */
.album-card {
  background: var(--black-soft);
  border: 1px solid var(--border-subtle);
  border-radius: 12px;
  overflow: hidden;
  transition: border-color var(--trans-fast), transform var(--trans-fast);
  display: flex;
  flex-direction: column;
}
.album-card:hover {
  border-color: var(--flag-green);
  transform: translateY(-2px);
}
.album-card__cover {
  display: block;
  position: relative;
  aspect-ratio: 16/9;
  overflow: hidden;
  background: var(--black-panel);
  text-decoration: none;
}
.album-card__cover img {
  width: 100%; height: 100%;
  object-fit: cover; display: block;
  transition: transform 400ms ease;
}
.album-card:hover .album-card__cover img { transform: scale(1.04); }
.album-card__cover-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 2.5rem; color: var(--text-dim); opacity: 0.3;
  background: linear-gradient(135deg, var(--black-panel) 0%, rgba(25,138,78,0.08) 100%);
}
.album-card__badges {
  position: absolute; top: 0.6rem; right: 0.6rem;
  display: flex; gap: 0.35rem; flex-wrap: wrap; justify-content: flex-end;
}
.badge {
  font-size: 0.7rem; font-weight: 600;
  padding: 0.2rem 0.5rem; border-radius: 20px;
  display: inline-flex; align-items: center; gap: 0.25rem;
  backdrop-filter: blur(4px);
}
.badge--gold { background: rgba(201,168,76,0.92); color: #0f0f0f; }
.badge--dark { background: rgba(0,0,0,0.72); color: #fff; border: 1px solid rgba(255,255,255,0.1); }
.album-card__category {
  position: absolute; bottom: 0.6rem; left: 0.6rem;
  font-size: 0.68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.05em;
  padding: 0.2rem 0.6rem; border-radius: 20px;
  background: rgba(25,138,78,0.9); color: #fff;
  backdrop-filter: blur(4px);
}
.album-cat--race     { background: rgba(192,57,43,0.9)  !important; }
.album-cat--training { background: rgba(74,124,89,0.9)  !important; }
.album-cat--lsd      { background: rgba(224,123,57,0.9) !important; }
.album-cat--social   { background: rgba(201,168,76,0.9) !important; color:#0f0f0f !important; }

.album-card__body {
  padding: 0.9rem 1rem 0.8rem;
  display: flex; flex-direction: column; gap: 0.5rem; flex: 1;
}
.album-card__title { margin: 0; }
.album-card__title a {
  font-size: 0.95rem; font-weight: 600;
  color: var(--off-white); text-decoration: none; line-height: 1.3;
}
.album-card__title a:hover { color: var(--green-bright); }
.album-card__meta {
  display: flex; flex-wrap: wrap; gap: 0.75rem;
  font-size: 0.75rem; color: var(--text-dim);
}
.album-card__meta span { display: inline-flex; align-items: center; gap: 0.3rem; }
.album-card__tags { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.tag {
  font-size: 0.68rem; padding: 0.15rem 0.5rem;
  background: rgba(255,255,255,0.06);
  border: 1px solid var(--border-mid);
  border-radius: 20px; color: var(--text-dim);
}
.album-card__actions {
  display: flex; gap: 0.4rem; flex-wrap: wrap;
  margin-top: auto; padding-top: 0.6rem;
  border-top: 1px solid var(--border-subtle);
}

/* Buttons */
.btn-sm {
  display: inline-flex; align-items: center; gap: 0.3rem;
  padding: 0.35rem 0.7rem; border-radius: 6px;
  font-size: 0.75rem; font-weight: 600;
  font-family: var(--font-body); cursor: pointer;
  text-decoration: none;
  transition: background var(--trans-fast), color var(--trans-fast);
  white-space: nowrap;
}
.btn-sm--ghost {
  background: rgba(255,255,255,0.06); color: var(--off-white);
  border: 1px solid var(--border-mid);
}
.btn-sm--ghost:hover { background: rgba(255,255,255,0.13); color: #fff; }
.btn-sm--danger {
  background: rgba(192,57,43,0.12); color: var(--flag-red);
  border: 1px solid rgba(192,57,43,0.28);
}
.btn-sm--danger:hover { background: rgba(192,57,43,0.28); }

/* Gallery error banner */
.gallery-error-banner {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.75rem 1rem;
  background: rgba(192,57,43,0.12);
  border: 1px solid rgba(192,57,43,0.3);
  border-radius: 8px; color: var(--flag-red);
  margin-bottom: 1.25rem; font-size: 0.875rem;
}

/* Modal */
.lfs-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.78);
  z-index: 1000; display: flex;
  align-items: center; justify-content: center;
}
.lfs-modal {
  background: var(--black-soft);
  border: 1px solid var(--border-mid);
  border-radius: 12px;
  width: 100%; max-width: 440px; margin: 1rem;
  box-shadow: 0 20px 60px rgba(0,0,0,0.6);
}
.lfs-modal__header {
  padding: 1.25rem 1.5rem 0.75rem;
  border-bottom: 1px solid var(--border-subtle);
}
.lfs-modal__header h2 {
  font-size: 1rem; font-weight: 700;
  display: flex; align-items: center; gap: 0.5rem; margin: 0;
}
.lfs-modal__body {
  padding: 1.25rem 1.5rem;
  color: var(--text-dim); font-size: 0.9rem; line-height: 1.6;
}
.lfs-modal__footer {
  padding: 0.75rem 1.5rem 1.25rem;
  display: flex; justify-content: flex-end; gap: 0.5rem;
}
</style>


<!-- ══════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════════ -->
<script>
function confirmDeleteAlbum(id, name) {
  document.getElementById('deleteAlbumName').textContent = '"' + name + '"';
  document.getElementById('deleteAlbumForm').action = '/admin/gallery/albums/' + id + '/delete';
  document.getElementById('deleteAlbumModal').style.display = 'flex';
}
function closeDeleteModal() {
  document.getElementById('deleteAlbumModal').style.display = 'none';
}
document.getElementById('deleteAlbumModal').addEventListener('click', function (e) {
  if (e.target === this) closeDeleteModal();
});
</script>

@endsection
