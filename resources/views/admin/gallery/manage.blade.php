@extends('layouts.admin')

@section('content')
@php
$alb  = $album ?? [];
$alId = htmlspecialchars($alb['id'] ?? '');

$pageTitle   = 'Gallery — ' . ($alb['title'] ?? 'Manage Album');
$activePage  = 'gallery';
$breadcrumbs = [
    ['label' => 'Gallery', 'url' => '/admin/gallery'],
    ['label' => 'Albums',  'url' => '/admin/gallery/albums'],
    ['label' => $alb['title'] ?? 'Album'],
];

$catSlug = strtolower(preg_replace('/\s+/', '-', $alb['category'] ?? 'other'));
$media   = $media ?? [];
@endphp

<!-- ══════════════════════════════════════════════
     ALBUM HEADER CARD
════════════════════════════════════════════════ -->
<div class="mgmt-album-header" style="margin-bottom:1.5rem;">

  @if(!empty($alb['coverImage']))
    <img src="{{ $alb['coverImage'] }}"
         alt="{{ $alb['title'] ?? '' }} cover"
         class="mgmt-album-cover" />
  @else
    <div class="mgmt-album-cover mgmt-album-cover--empty">
      <i class="fas fa-images"></i>
    </div>
  @endif

  <div class="mgmt-album-info">
    <div style="display:flex; align-items:flex-start; gap:0.75rem; flex-wrap:wrap;">
      <h2 style="font-size:1.3rem; font-weight:700; color:var(--off-white); flex:1;">
        {{ $alb['title'] ?? '—' }}
      </h2>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        @if(!empty($alb['featured']))
          <span class="badge badge--gold"><i class="fas fa-star"></i> Featured</span>
        @endif
        @if(!empty($alb['homepageSlider']))
          <span class="badge badge--green"><i class="fas fa-sliders"></i> Slider</span>
        @endif
        <span class="badge badge--cat album-cat--{{ $catSlug }}">
          {{ $alb['category'] ?? 'General' }}
        </span>
      </div>
    </div>

    @if(!empty($alb['description']))
      <p style="font-size:0.85rem; color:var(--text-dim); margin-top:0.4rem; line-height:1.5;">
        {{ $alb['description'] }}
      </p>
    @endif

    <div class="mgmt-album-meta">
      @if(!empty($alb['date']))
        <span>
          <i class="fas fa-calendar" aria-hidden="true"></i>
          {{ date('j M Y', strtotime($alb['date'])) }}
        </span>
      @endif
      @if(!empty($alb['location']))
        <span><i class="fas fa-location-dot" aria-hidden="true"></i> {{ $alb['location'] }}</span>
      @endif
      @if(!empty($alb['event']))
        <span><i class="fas fa-flag" aria-hidden="true"></i> {{ $alb['event'] }}</span>
      @endif
      <span><i class="fas fa-images" aria-hidden="true"></i> {{ (int)($alb['mediaCount'] ?? 0) }} items</span>
    </div>

    @if(!empty($alb['tags']))
      <div style="display:flex; flex-wrap:wrap; gap:0.3rem; margin-top:0.5rem;">
        @foreach((array)$alb['tags'] as $tag)
          <span class="tag">#{{ $tag }}</span>
        @endforeach
      </div>
    @endif

    <!-- Album actions -->
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.75rem;">
      <a href="/admin/gallery/upload?album={{ $alId }}" class="btn-action btn-action--primary">
        <i class="fas fa-upload"></i> Upload Media
      </a>
      <a href="/admin/gallery/albums/{{ $alId }}/edit" class="btn-action btn-action--ghost">
        <i class="fas fa-pen"></i> Edit Album
      </a>
      <button type="button" class="btn-action btn-action--ghost" onclick="toggleFeatured()">
        <i class="fas fa-star"></i> {{ !empty($alb['featured']) ? 'Unfeature' : 'Feature' }}
      </button>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     MEDIA TOOLBAR
════════════════════════════════════════════════ -->
<div class="mgmt-toolbar" style="margin-bottom:1rem;">

  <div style="display:flex; align-items:center; gap:0.5rem;">
    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"
           style="width:16px;height:16px;cursor:pointer;" />
    <label for="selectAll" style="font-size:0.8rem; color:var(--text-dim); cursor:pointer;">Select All</label>
  </div>

  <!-- Bulk actions (hidden until selection) -->
  <div id="bulkActions" style="display:none; align-items:center; gap:0.5rem;">
    <span id="selectedCount" style="font-size:0.8rem; color:var(--text-dim);"></span>
    <button type="button" class="btn-action btn-action--ghost" onclick="bulkDelete()">
      <i class="fas fa-trash"></i> Delete
    </button>
    <div style="position:relative;" id="moveDropdownWrapper">
      <button type="button" class="btn-action btn-action--ghost" onclick="toggleMoveDropdown()">
        <i class="fas fa-folder-arrow-up"></i> Move to Album
      </button>
    </div>
    <button type="button" class="btn-action btn-action--ghost" onclick="bulkFeature()">
      <i class="fas fa-star"></i> Feature Selected
    </button>
  </div>

  <!-- View toggle -->
  <div style="margin-left:auto; display:flex; gap:0.25rem;">
    <button type="button" class="view-btn active" id="viewGrid"
            onclick="setView('grid')" title="Grid view">
      <i class="fas fa-grip"></i>
    </button>
    <button type="button" class="view-btn" id="viewList"
            onclick="setView('list')" title="List view">
      <i class="fas fa-list"></i>
    </button>
  </div>

  <!-- Sort -->
  <select id="sortSelect" onchange="sortMedia(this.value)"
          style="padding:0.45rem 0.65rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-size:0.8rem; font-family:var(--font-body);">
    <option value="newest">Newest first</option>
    <option value="oldest">Oldest first</option>
    <option value="featured">Featured first</option>
  </select>

</div>


<!-- ══════════════════════════════════════════════
     MEDIA GRID
════════════════════════════════════════════════ -->
@if(!empty($media))

  <div class="mgmt-grid" id="mediaGrid">
    @foreach($media as $item)
      @php
        $itemId      = htmlspecialchars($item['id']);
        $thumbSrc    = htmlspecialchars($item['urls']['thumbnail'] ?? $item['urls']['medium'] ?? '');
        $originalSrc = htmlspecialchars($item['urls']['original'] ?? '');
        $caption     = htmlspecialchars($item['caption'] ?? '');
        $starColor   = !empty($item['featured']) ? 'var(--gold)' : 'var(--text-dim)';
      @endphp
      <div class="media-item" id="mi_{{ $itemId }}"
           draggable="true"
           ondragstart="dragStart(event,'{{ $itemId }}')"
           ondragover="dragOver(event)"
           ondrop="dragDrop(event,'{{ $itemId }}')">

        <!-- Checkbox -->
        <div class="media-item__check">
          <input type="checkbox" class="media-checkbox" value="{{ $itemId }}"
                 onchange="updateBulkActions()" />
        </div>

        <!-- Thumbnail -->
        <div class="media-item__thumb" onclick="openPreview('{{ $itemId }}')">
          @if(($item['type'] ?? '') === 'video')
            <video src="{{ $originalSrc }}" class="media-thumb-img" muted preload="metadata"></video>
            <div class="media-item__play"><i class="fas fa-play"></i></div>
          @else
            <img src="{{ $thumbSrc }}"
                 alt="{{ $caption ?: htmlspecialchars($item['filename'] ?? '') }}"
                 loading="lazy"
                 class="media-thumb-img" />
          @endif

          @if(!empty($item['featured']))
            <span class="media-item__featured-badge" title="Featured"><i class="fas fa-star"></i></span>
          @endif
          @if(!empty($item['homepageSlider']))
            <span class="media-item__flag-badge media-item__flag-badge--slider" title="Homepage slider">Slider</span>
          @endif
          @if(!empty($item['eventHighlight']))
            <span class="media-item__flag-badge media-item__flag-badge--event" title="Event highlight">Event</span>
          @endif

          <div class="media-item__drag-handle" title="Drag to reorder">
            <i class="fas fa-grip-dots-vertical"></i>
          </div>
        </div>

        <!-- Quick actions -->
        <div class="media-item__actions">
          <button type="button" class="icon-btn" title="Preview"
                  onclick="openPreview('{{ $itemId }}')">
            <i class="fas fa-eye"></i>
          </button>
          <button type="button" class="icon-btn" title="{{ !empty($item['featured']) ? 'Unfeature' : 'Feature' }}"
                  onclick="toggleMediaFeatured('{{ $itemId }}', this)">
            <i class="fas fa-star" style="color:{{ $starColor }}"></i>
          </button>
          <button type="button" class="icon-btn" title="{{ !empty($item['homepageSlider']) ? 'Remove from homepage slider' : 'Add to homepage slider' }}"
                  onclick="toggleMediaHomepageSlider('{{ $itemId }}', this)">
            <i class="fas fa-sliders" style="color:{{ !empty($item['homepageSlider']) ? 'var(--flag-green)' : 'var(--text-dim)' }}"></i>
          </button>
          <button type="button" class="icon-btn" title="{{ !empty($item['eventHighlight']) ? 'Remove from event highlight' : 'Add to event highlight' }}"
                  onclick="toggleMediaEventHighlight('{{ $itemId }}', this)">
            <i class="fas fa-calendar-day" style="color:{{ !empty($item['eventHighlight']) ? 'var(--flag-orange)' : 'var(--text-dim)' }}"></i>
          </button>
          <button type="button" class="icon-btn icon-btn--danger" title="Delete"
                  onclick="deleteMedia('{{ $itemId }}')">
            <i class="fas fa-trash"></i>
          </button>
        </div>

        <!-- Caption (editable) -->
        <div class="media-item__caption">
          <input type="text"
                 class="caption-input"
                 placeholder="Add caption…"
                 value="{{ $caption }}"
                 onblur="saveCaption('{{ $itemId }}', this.value)" />
        </div>

      </div>
    @endforeach
  </div>

@else

  <!-- Empty state -->
  <div style="text-align:center; padding:4rem 2rem; color:var(--text-dim);">
    <i class="fas fa-photo-film" style="font-size:3rem; margin-bottom:1rem; display:block; opacity:0.35;"></i>
    <p style="font-size:1rem; margin-bottom:0.5rem;">No media in this album yet</p>
    <p style="font-size:0.85rem; margin-bottom:1.5rem;">Upload photos or videos to get started.</p>
    <a href="/admin/gallery/upload?album={{ $alId }}"
       style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.65rem 1.25rem; background:var(--flag-green); color:#fff; border-radius:8px; text-decoration:none; font-weight:600;">
      <i class="fas fa-upload"></i> Upload Media
    </a>
  </div>

@endif


<!-- ══════════════════════════════════════════════
     PREVIEW MODAL
════════════════════════════════════════════════ -->
<div id="previewModal" class="preview-modal-overlay" style="display:none;"
     role="dialog" aria-modal="true" aria-label="Media preview">

  <button class="preview-modal__close" onclick="closePreview()" aria-label="Close preview">
    <i class="fas fa-xmark"></i>
  </button>
  <button class="preview-modal__nav prev" onclick="prevMedia()" aria-label="Previous">
    <i class="fas fa-chevron-left"></i>
  </button>

  <div class="preview-modal__content" id="previewContent">
    <!-- Injected by JS -->
  </div>

  <button class="preview-modal__nav next" onclick="nextMedia()" aria-label="Next">
    <i class="fas fa-chevron-right"></i>
  </button>

  <div class="preview-modal__meta" id="previewMeta">
    <p id="previewCaption" style="font-size:0.9rem; color:var(--off-white); margin-bottom:0.5rem;"></p>
    <p id="previewFilename" style="font-size:0.75rem; color:var(--text-dim);"></p>
    <div style="margin-top:0.75rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
      <button class="btn-action btn-action--ghost" id="previewFeatureBtn" onclick="featureFromPreview()">
        <i class="fas fa-star"></i> Feature
      </button>
      <a id="previewDownloadBtn" href="#" download class="btn-action btn-action--ghost">
        <i class="fas fa-download"></i> Download
      </a>
      <button class="btn-action btn-action--danger" onclick="deleteFromPreview()">
        <i class="fas fa-trash"></i> Delete
      </button>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     DELETE CONFIRMATION MODAL
════════════════════════════════════════════════ -->
<div id="mediaDeleteModal" class="mgmt-delete-overlay mgmt-delete-overlay--hidden"
     role="dialog" aria-modal="true" aria-labelledby="mediaDeleteTitle">
  <div class="mgmt-delete-dialog">
    <h2 id="mediaDeleteTitle" class="mgmt-delete-title">
      <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
      Delete media
    </h2>
    <p id="mediaDeleteBody" class="mgmt-delete-body">
      Are you sure you want to delete this item? This action cannot be undone.
    </p>
    <div class="mgmt-delete-actions">
      <button type="button" class="btn-action btn-action--ghost" id="deleteCancelBtn">
        Cancel
      </button>
      <button type="button" class="btn-action btn-action--danger" id="deleteConfirmBtn">
        <i class="fas fa-trash" aria-hidden="true"></i> Delete
      </button>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     STYLES
════════════════════════════════════════════════ -->
<style>
/* Album header */
.mgmt-album-header { display:flex; gap:1.25rem; background:var(--black-soft); border:1px solid var(--border-subtle); border-radius:12px; padding:1.25rem; align-items:flex-start; }
.mgmt-album-cover { width:140px; height:90px; object-fit:cover; border-radius:8px; flex-shrink:0; }
.mgmt-album-cover--empty { width:140px; height:90px; border-radius:8px; flex-shrink:0; background:var(--black-panel); display:flex; align-items:center; justify-content:center; font-size:2rem; color:var(--text-dim); opacity:0.35; }
.mgmt-album-info { flex:1; min-width:0; }
.mgmt-album-meta { display:flex; flex-wrap:wrap; gap:0.75rem; font-size:0.78rem; color:var(--text-dim); margin-top:0.5rem; }
.mgmt-album-meta span { display:flex; align-items:center; gap:0.3rem; }

/* Toolbar */
.mgmt-toolbar { display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; padding:0.65rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-subtle); border-radius:10px; }

/* Buttons */
.btn-action { display:inline-flex; align-items:center; gap:0.4rem; padding:0.45rem 0.9rem; border-radius:7px; font-size:0.82rem; font-weight:600; font-family:var(--font-body); cursor:pointer; text-decoration:none; border:none; transition:background var(--trans-fast); }
.btn-action--primary { background:var(--flag-green); color:#fff; }
.btn-action--primary:hover { background:var(--green); }
.btn-action--ghost { background:rgba(255,255,255,0.06); color:var(--off-white); border:1px solid var(--border-mid); }
.btn-action--ghost:hover { background:rgba(255,255,255,0.12); }
.btn-action--danger { background:rgba(192,57,43,0.15); color:var(--flag-red); border:1px solid rgba(192,57,43,0.3); }
.btn-action--danger:hover { background:rgba(192,57,43,0.3); }

/* View toggle */
.view-btn { padding:0.4rem 0.6rem; background:rgba(255,255,255,0.04); border:1px solid var(--border-subtle); border-radius:6px; color:var(--text-dim); cursor:pointer; font-size:0.8rem; transition:background var(--trans-fast); }
.view-btn.active { background:var(--flag-green); color:#fff; border-color:var(--flag-green); }

/* Media grid */
.mgmt-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:0.75rem; }
.mgmt-grid.list-view { grid-template-columns:1fr; }
.mgmt-grid.list-view .media-item { display:flex; align-items:center; gap:0.75rem; border-radius:8px; }
.mgmt-grid.list-view .media-item__thumb { width:80px; height:56px; flex-shrink:0; aspect-ratio:unset; }
.mgmt-grid.list-view .media-item__caption { flex:1; }
.mgmt-grid.list-view .media-item__actions { flex-direction:row; }

/* Media item */
.media-item { background:var(--black-soft); border:1px solid var(--border-subtle); border-radius:10px; overflow:hidden; position:relative; transition:border-color var(--trans-fast); }
.media-item:hover { border-color:var(--flag-green); }
.media-item.selected { border-color:var(--flag-green); box-shadow:0 0 0 2px rgba(25,138,78,0.3); }
.media-item__check { position:absolute; top:0.45rem; left:0.45rem; z-index:2; }
.media-item__thumb { position:relative; aspect-ratio:1; overflow:hidden; background:var(--black-panel); cursor:pointer; }
.media-thumb-img { width:100%; height:100%; object-fit:cover; transition:transform 300ms ease; }
.media-item:hover .media-thumb-img { transform:scale(1.04); }
.media-item__play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.4); color:#fff; font-size:1.4rem; }
.media-item__featured-badge { position:absolute; top:0.4rem; right:0.4rem; background:rgba(201,168,76,0.9); color:#0f0f0f; width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.65rem; }
.media-item__flag-badge { position:absolute; top:0.4rem; left:0.4rem; font-size:0.55rem; font-weight:700; padding:0.15rem 0.35rem; border-radius:4px; text-transform:uppercase; }
.media-item__flag-badge--slider { background:rgba(25,138,78,0.9); color:#fff; }
.media-item__flag-badge--event { background:rgba(224,123,57,0.9); color:#fff; top:2rem; }
.media-item__drag-handle { position:absolute; bottom:0.35rem; right:0.35rem; color:rgba(255,255,255,0.4); font-size:0.75rem; cursor:grab; opacity:0; transition:opacity var(--trans-fast); }
.media-item:hover .media-item__drag-handle { opacity:1; }
.media-item__actions { display:flex; justify-content:center; gap:0.2rem; padding:0.35rem 0.4rem 0; }
.icon-btn { width:28px; height:28px; border-radius:6px; background:rgba(255,255,255,0.06); border:1px solid var(--border-subtle); color:var(--text-dim); font-size:0.72rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background var(--trans-fast),color var(--trans-fast); }
.icon-btn:hover { background:rgba(255,255,255,0.14); color:var(--off-white); }
.icon-btn--danger:hover { background:rgba(192,57,43,0.3); color:var(--flag-red); }
.media-item__caption { padding:0.3rem 0.5rem 0.45rem; }
.caption-input { width:100%; background:transparent; border:none; border-bottom:1px solid transparent; color:var(--text-dim); font-family:var(--font-body); font-size:0.73rem; padding:0.1rem 0.15rem; outline:none; transition:border-color var(--trans-fast); }
.caption-input:focus { border-bottom-color:var(--flag-green); color:var(--off-white); }

/* Badges */
.badge { font-size:0.7rem; font-weight:600; padding:0.2rem 0.5rem; border-radius:20px; display:inline-flex; align-items:center; gap:0.25rem; }
.badge--gold  { background:rgba(201,168,76,0.9); color:#0f0f0f; }
.badge--green { background:rgba(25,138,78,0.9);  color:#fff; }
.badge--cat   { background:rgba(25,138,78,0.85); color:#fff; }
.badge--cat.album-cat--race   { background:rgba(192,57,43,0.85); }
.badge--cat.album-cat--lsd    { background:rgba(224,123,57,0.85); }
.badge--cat.album-cat--social { background:rgba(201,168,76,0.85); color:#0f0f0f; }
.tag { font-size:0.68rem; padding:0.15rem 0.5rem; background:rgba(255,255,255,0.06); border:1px solid var(--border-mid); border-radius:20px; color:var(--text-dim); }

/* Preview modal */
.preview-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.9); z-index:2000; display:flex; align-items:center; justify-content:center; }
.preview-modal__close { position:absolute; top:1rem; right:1rem; width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,0.1); border:none; color:#fff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background var(--trans-fast); z-index:10; }
.preview-modal__close:hover { background:rgba(255,255,255,0.2); }
.preview-modal__nav { position:absolute; top:50%; transform:translateY(-50%); width:44px; height:44px; background:rgba(255,255,255,0.1); border:none; border-radius:50%; color:#fff; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:10; transition:background var(--trans-fast); }
.preview-modal__nav:hover { background:rgba(255,255,255,0.2); }
.preview-modal__nav.prev { left:1rem; }
.preview-modal__nav.next { right:5rem; }
.preview-modal__content { max-width:80vw; max-height:75vh; display:flex; align-items:center; justify-content:center; }
.preview-modal__content img,
.preview-modal__content video { max-width:100%; max-height:75vh; object-fit:contain; border-radius:6px; }
.preview-modal__meta { position:absolute; bottom:1.5rem; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); border-radius:12px; padding:0.85rem 1.25rem; min-width:280px; max-width:500px; text-align:center; }

/* Delete confirmation modal */
.mgmt-delete-overlay {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.78);
  z-index: 2100;
}
.mgmt-delete-overlay--hidden {
  display: none;
}
.mgmt-delete-dialog {
  background: var(--black-soft);
  border-radius: 12px;
  border: 1px solid var(--border-mid);
  padding: 1.25rem 1.5rem 1.1rem;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.7);
}
.mgmt-delete-title {
  margin: 0 0 0.6rem;
  font-size: 1rem;
  font-weight: 700;
  color: var(--off-white);
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.mgmt-delete-title i {
  color: var(--flag-red);
}
.mgmt-delete-body {
  margin: 0;
  font-size: 0.88rem;
  color: var(--text-dim);
}
.mgmt-delete-actions {
  margin-top: 1rem;
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>


<!-- ══════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════════ -->
@php
$mediaPayload = array_map(function ($m) {
    return [
        '_id'            => $m['id']       ?? '',
        'filename'       => $m['filename'] ?? '',
        'type'           => $m['type']     ?? 'photo',
        'caption'        => $m['caption']  ?? '',
        'featured'       => !empty($m['featured']),
        'homepageSlider' => !empty($m['homepageSlider']),
        'eventHighlight' => !empty($m['eventHighlight']),
        'urls'           => $m['urls']     ?? [],
    ];
}, $media);
@endphp
<script>
/* ─── Media data injected from server ─── */
const MEDIA_DATA = {!! json_encode($mediaPayload, JSON_HEX_TAG | JSON_HEX_AMP) !!};
const ALBUM_ID = {!! json_encode($alb['id'] ?? '') !!};
let currentPreviewIdx = 0;

/* ─── CSRF helper ─── */
const CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
function withCsrf(headers = {}) {
  return Object.assign({}, headers, CSRF_TOKEN ? { 'X-CSRF-Token': CSRF_TOKEN } : {});
}

/* ─── Selection ─── */
function toggleSelectAll(cb) {
  document.querySelectorAll('.media-checkbox').forEach(el => {
    el.checked = cb.checked;
    el.closest('.media-item').classList.toggle('selected', cb.checked);
  });
  updateBulkActions();
}

function updateBulkActions() {
  const checked = document.querySelectorAll('.media-checkbox:checked');
  const bulk    = document.getElementById('bulkActions');
  bulk.style.display = checked.length > 0 ? 'flex' : 'none';
  document.getElementById('selectedCount').textContent = checked.length + ' selected';
  checked.forEach(el => el.closest('.media-item').classList.add('selected'));
  document.querySelectorAll('.media-checkbox:not(:checked)').forEach(el => {
    el.closest('.media-item').classList.remove('selected');
  });
}

function getSelectedIds() {
  return Array.from(document.querySelectorAll('.media-checkbox:checked')).map(el => el.value);
}

/* ─── Bulk actions ─── */
async function bulkDelete() {
  const ids = getSelectedIds();
  if (!ids.length) return;
  openDeleteModal(ids, 'bulk');
}

async function bulkFeature() {
  const ids = getSelectedIds();
  if (!ids.length) return;
  await fetch('/admin/gallery/media/bulk-feature', {
    method:  'POST',
    headers: withCsrf({ 'Content-Type': 'application/json' }),
    body:    JSON.stringify({ ids }),
  });
  alert(ids.length + ' item(s) marked as featured.');
}

function toggleMoveDropdown() {
  alert('Move to album: feature coming soon.');
}

/* ─── View toggle ─── */
function setView(type) {
  const grid = document.getElementById('mediaGrid');
  if (!grid) return;
  document.getElementById('viewGrid').classList.toggle('active', type === 'grid');
  document.getElementById('viewList').classList.toggle('active', type === 'list');
  grid.classList.toggle('list-view', type === 'list');
}

/* ─── Sort ─── */
function sortMedia(val) {
  window.location.href = '/admin/gallery/albums/' + ALBUM_ID + '/manage?sort=' + val;
}

/* ─── Drag & drop reorder ─── */
let dragSrcId = null;
function dragStart(e, id) { dragSrcId = id; e.dataTransfer.effectAllowed = 'move'; }
function dragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
async function dragDrop(e, targetId) {
  e.preventDefault();
  if (dragSrcId === targetId) return;
  await fetch('/admin/gallery/media/reorder', {
    method:  'POST',
    headers: withCsrf({ 'Content-Type': 'application/json' }),
    body:    JSON.stringify({ sourceId: dragSrcId, targetId, albumId: ALBUM_ID }),
  });
  location.reload();
}

/* ─── Caption save ─── */
async function saveCaption(id, caption) {
  await fetch('/admin/gallery/media/' + id + '/caption', {
    method:  'PATCH',
    headers: withCsrf({ 'Content-Type': 'application/json' }),
    body:    JSON.stringify({ caption }),
  });
}

/* ─── Feature toggle ─── */
async function toggleMediaFeatured(id, btn) {
  try {
    const res = await fetch('/admin/gallery/media/' + id + '/feature', {
      method:  'PATCH',
      headers: withCsrf(),
    });
    if (!res.ok) { alert('Unable to update featured state. Please try again.'); return; }
    const data       = await res.json();
    const isFeatured = !!data.featured;
    const icon       = btn.querySelector('i');
    if (icon) icon.style.color = isFeatured ? 'var(--gold)' : 'var(--text-dim)';
    const itemEl = document.getElementById('mi_' + id);
    if (itemEl) {
      let badge = itemEl.querySelector('.media-item__featured-badge');
      if (isFeatured && !badge) {
        badge = document.createElement('span');
        badge.className = 'media-item__featured-badge';
        badge.innerHTML = '<i class="fas fa-star"></i>';
        itemEl.querySelector('.media-item__thumb')?.appendChild(badge);
      } else if (!isFeatured && badge) {
        badge.remove();
      }
    }
    const idx = MEDIA_DATA.findIndex(m => m._id === id);
    if (idx !== -1) MEDIA_DATA[idx].featured = isFeatured;
    if (btn.id === 'previewFeatureBtn') {
      btn.innerHTML = '<i class="fas fa-star"></i> ' + (isFeatured ? 'Unfeature' : 'Feature');
    }
  } catch { alert('An error occurred while toggling featured.'); }
}

/* ─── Homepage slider toggle ─── */
async function toggleMediaHomepageSlider(id, btn) {
  try {
    const res = await fetch('/admin/gallery/media/' + id + '/homepage-slider', {
      method: 'PATCH',
      headers: withCsrf(),
    });
    if (!res.ok) { alert('Unable to update homepage slider.'); return; }
    const data = await res.json();
    const on = !!data.homepageSlider;
    const itemEl = document.getElementById('mi_' + id);
    if (itemEl) {
      let badge = itemEl.querySelector('.media-item__flag-badge--slider');
      if (on && !badge) {
        badge = document.createElement('span');
        badge.className = 'media-item__flag-badge media-item__flag-badge--slider';
        badge.title = 'Homepage slider';
        badge.textContent = 'Slider';
        itemEl.querySelector('.media-item__thumb')?.appendChild(badge);
      } else if (!on && badge) badge.remove();
      const icon = btn?.querySelector('i');
      if (icon) icon.style.color = on ? 'var(--flag-green)' : 'var(--text-dim)';
    }
    const idx = MEDIA_DATA.findIndex(m => m._id === id);
    if (idx !== -1) MEDIA_DATA[idx].homepageSlider = on;
  } catch { alert('An error occurred.'); }
}

/* ─── Event highlight toggle ─── */
async function toggleMediaEventHighlight(id, btn) {
  try {
    const res = await fetch('/admin/gallery/media/' + id + '/event-highlight', {
      method: 'PATCH',
      headers: withCsrf(),
    });
    if (!res.ok) { alert('Unable to update event highlight.'); return; }
    const data = await res.json();
    const on = !!data.eventHighlight;
    const itemEl = document.getElementById('mi_' + id);
    if (itemEl) {
      let badge = itemEl.querySelector('.media-item__flag-badge--event');
      if (on && !badge) {
        badge = document.createElement('span');
        badge.className = 'media-item__flag-badge media-item__flag-badge--event';
        badge.title = 'Event highlight';
        badge.textContent = 'Event';
        itemEl.querySelector('.media-item__thumb')?.appendChild(badge);
      } else if (!on && badge) badge.remove();
      const icon = btn?.querySelector('i');
      if (icon) icon.style.color = on ? 'var(--flag-orange)' : 'var(--text-dim)';
    }
    const idx = MEDIA_DATA.findIndex(m => m._id === id);
    if (idx !== -1) MEDIA_DATA[idx].eventHighlight = on;
  } catch { alert('An error occurred.'); }
}

/* ─── Delete single ─── */
async function deleteMedia(id) {
  openDeleteModal(id, 'single');
}

/* ─── Album featured toggle ─── */
async function toggleFeatured() {
  const res = await fetch('/admin/gallery/albums/' + ALBUM_ID + '/feature', {
    method:  'PATCH',
    headers: withCsrf(),
  });
  if (res.ok) location.reload();
}

/* ─── Preview modal ─── */
function openPreview(id) {
  currentPreviewIdx = MEDIA_DATA.findIndex(m => m._id === id);
  renderPreview(currentPreviewIdx);
  document.getElementById('previewModal').style.display = 'flex';
  document.addEventListener('keydown', onPreviewKeydown);
}
function closePreview() {
  document.getElementById('previewModal').style.display = 'none';
  document.removeEventListener('keydown', onPreviewKeydown);
}
function prevMedia() { if (currentPreviewIdx > 0) renderPreview(--currentPreviewIdx); }
function nextMedia() { if (currentPreviewIdx < MEDIA_DATA.length - 1) renderPreview(++currentPreviewIdx); }
function onPreviewKeydown(e) {
  if (e.key === 'ArrowLeft')  prevMedia();
  if (e.key === 'ArrowRight') nextMedia();
  if (e.key === 'Escape')     closePreview();
}
function renderPreview(idx) {
  const item = MEDIA_DATA[idx];
  if (!item) return;
  const content = document.getElementById('previewContent');
  if (item.type === 'video') {
    content.innerHTML = '<video src="' + (item.urls?.original || '') + '" controls style="max-width:80vw;max-height:75vh;"></video>';
  } else {
    content.innerHTML = '<img src="' + (item.urls?.large || item.urls?.medium || '') + '" alt="' + (item.caption || '') + '" />';
  }
  document.getElementById('previewCaption').textContent  = item.caption   || '';
  document.getElementById('previewFilename').textContent = item.filename  || '';
  document.getElementById('previewDownloadBtn').href     = item.urls?.original || '#';
  document.getElementById('previewFeatureBtn').innerHTML =
    '<i class="fas fa-star"></i> ' + (item.featured ? 'Unfeature' : 'Feature');
}
function featureFromPreview() {
  const item = MEDIA_DATA[currentPreviewIdx];
  if (item) toggleMediaFeatured(item._id, document.getElementById('previewFeatureBtn'));
}
function deleteFromPreview() {
  const item = MEDIA_DATA[currentPreviewIdx];
  if (item) {
    closePreview();
    openDeleteModal(item._id, 'single');
  }
}
document.getElementById('previewModal').addEventListener('click', function (e) {
  if (e.target === this) closePreview();
});

/* ─── Delete confirmation modal wiring ─── */
let pendingDeleteIds = [];
let pendingDeleteMode = 'single'; // 'single' | 'bulk'

function openDeleteModal(ids, mode = 'single') {
  pendingDeleteIds = Array.isArray(ids) ? ids : [ids];
  pendingDeleteMode = mode;

  const modal = document.getElementById('mediaDeleteModal');
  const body  = document.getElementById('mediaDeleteBody');
  if (!modal || !body) return;

  if (pendingDeleteMode === 'bulk') {
    body.textContent = 'Delete ' + pendingDeleteIds.length + ' selected item(s)? This action cannot be undone.';
  } else {
    body.textContent = 'Delete this item permanently? This action cannot be undone.';
  }

  modal.classList.remove('mgmt-delete-overlay--hidden');
}

function closeDeleteModal() {
  const modal = document.getElementById('mediaDeleteModal');
  if (modal) modal.classList.add('mgmt-delete-overlay--hidden');
}

async function performSingleDelete(id) {
  const res = await fetch('/admin/gallery/media/' + id, {
    method:  'DELETE',
    headers: withCsrf(),
  });
  if (res.ok) {
    const el = document.getElementById('mi_' + id);
    if (el) el.remove();
    updateBulkActions();
  }
}

async function performBulkDelete(ids) {
  if (!ids.length) return;

  const params = new URLSearchParams();
  ids.forEach(id => params.append('ids[]', id));

  await fetch('/admin/gallery/media/bulk-delete', {
    method:  'POST',
    headers: withCsrf({ 'Content-Type': 'application/x-www-form-urlencoded' }),
    body:    params.toString(),
  });

  ids.forEach(id => {
    const el = document.getElementById('mi_' + id);
    if (el) el.remove();
  });
  updateBulkActions();
}

document.getElementById('deleteConfirmBtn').addEventListener('click', async function () {
  if (!pendingDeleteIds.length) {
    closeDeleteModal();
    return;
  }
  if (pendingDeleteMode === 'bulk') {
    await performBulkDelete(pendingDeleteIds);
  } else {
    await performSingleDelete(pendingDeleteIds[0]);
  }
  closeDeleteModal();
});

document.getElementById('deleteCancelBtn').addEventListener('click', function () {
  closeDeleteModal();
});

document.getElementById('mediaDeleteModal').addEventListener('click', function (e) {
  if (e.target === this) {
    closeDeleteModal();
  }
});
</script>

@endsection
