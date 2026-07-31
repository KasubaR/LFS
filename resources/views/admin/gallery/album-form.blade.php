@extends('layouts.admin')

@section('content')
@php
$isEdit      = isset($album) && $album !== null;
$al          = $album ?? [];
$pageTitle   = $pageTitle ?? ($isEdit ? 'Gallery — Edit Album' : 'Gallery — Create Album');
$activePage  = 'gallery';
$breadcrumbs = $breadcrumbs ?? [
    ['label' => 'Gallery', 'url' => '/admin/gallery'],
    ['label' => 'Albums',  'url' => '/admin/gallery/albums'],
    ['label' => $isEdit ? ($al['title'] ?? 'Edit') : 'Create Album'],
];
$cats = $categories ?? ['Race', 'Training', 'LSD', 'Social'];

/** Format a date value to YYYY-MM-DD for a date input. */
if (! function_exists('formatDateInput')) {
    function formatDateInput(mixed $d): string {
        if (!$d) return '';
        $ts = is_numeric($d) ? (int)$d : strtotime((string)$d);
        return $ts ? gmdate('Y-m-d', $ts) : '';
    }
}

$tagsValue = !empty($al['tags']) ? implode(', ', (array)$al['tags']) : '';
@endphp

<div style="max-width:720px;">

  <form method="POST"
        action="{{ $isEdit ? '/admin/gallery/albums/' . htmlspecialchars($al['id']) : '/admin/gallery/albums' }}"
        id="albumForm">

    <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">

    <!-- Title -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="title">Title <span style="color:var(--flag-red);">*</span></label>
      <input type="text" id="title" name="title" required
             value="{{ $al['title'] ?? '' }}"
             placeholder="e.g. Sunrise 10K 2024"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
    </div>

    <!-- Description -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="description">Description</label>
      <textarea id="description" name="description" rows="3" placeholder="Optional short description"
                style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem; resize:vertical;"
      >{{ $al['description'] ?? '' }}</textarea>
    </div>

    <!-- Category -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="category">Category</label>
      <select id="category" name="category"
              style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;">
        @foreach($cats as $c)
          <option value="{{ $c }}"
            {{ ($al['category'] ?? '') === $c ? 'selected' : '' }}>
            {{ $c }}
          </option>
        @endforeach
      </select>
    </div>

    <!-- Date -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="date">Date</label>
      <input type="date" id="date" name="date"
             value="{{ formatDateInput($al['date'] ?? null) }}"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
    </div>

    <!-- Location -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="location">Location</label>
      <input type="text" id="location" name="location"
             value="{{ $al['location'] ?? '' }}"
             placeholder="e.g. Lusaka"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
    </div>

    <!-- Event -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="event">Event</label>
      <input type="text" id="event" name="event"
             value="{{ $al['event'] ?? '' }}"
             placeholder="e.g. LFS Sunrise 10K"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
    </div>

    <!-- Tags (comma-separated) -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="tags">Tags</label>
      <input type="text" id="tags" name="tags"
             value="{{ $tagsValue }}"
             placeholder="e.g. race, 10k, sunrise"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      <small style="display:block; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">Comma-separated; stored lowercase.</small>
    </div>

    <!-- Cover image -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="coverImage">Cover image</label>

      <!-- Stored URL (relative paths allowed, so text not url type) -->
      <input type="text" id="coverImage" name="coverImage"
             value="{{ $al['coverImage'] ?? '' }}"
             placeholder="/uploads/gallery/covers/album-cover-....webp"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem; margin-bottom:0.5rem;" />

      <!-- Upload control -->
      <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; margin-bottom:0.35rem;">
        <input type="file" id="coverImageFile"
               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
               style="flex:1; min-width:220px; padding:0.4rem; background:transparent; color:var(--off-white);" />
        <button type="button" id="uploadCoverBtn"
                style="padding:0.5rem 0.9rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.8rem; font-weight:600; cursor:pointer; white-space:nowrap;">
          <i class="fas fa-upload" aria-hidden="true"></i> Upload cover
        </button>
      </div>

      <div id="coverUploadStatus" style="font-size:0.8rem; color:var(--text-dim); min-height:1.2em;"></div>

      <!-- Preview -->
      <div id="coverImagePreview" style="margin-top:0.5rem; {{ ($isEdit && !empty($al['coverImage'])) ? '' : 'display:none;' }}">
        <div style="font-size:0.8rem; color:var(--text-dim); margin-bottom:0.25rem;">Cover preview:</div>
        <img id="coverImagePreviewImg"
             src="{{ $al['coverImage'] ?? '' }}"
             alt="Album cover preview"
             style="max-width:220px; border-radius:8px; border:1px solid var(--border-mid); display:block;" />
      </div>

      <small style="display:block; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">
        You can paste a URL or upload an image. Uploads are stored under <code>/uploads/gallery/covers/</code> and the URL is filled in automatically.
      </small>
    </div>

    <!-- External album link -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="externalUrl">External album link</label>
      <input type="url" id="externalUrl" name="externalUrl"
             value="{{ $al['externalUrl'] ?? '' }}"
             placeholder="https://example.com/your-album"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      <small style="display:block; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">
        Optional. If provided, visitors clicking this album on the public gallery will be taken to this link instead of the LFS album page.
      </small>
    </div>

    <!-- Featured / Homepage slider / Event highlight are set per photo on the album Manage page. -->

    <!-- Sort priority -->
    <div class="form-group" style="margin-bottom:1.5rem;">
      <label class="form-label" for="sortPriority">Sort priority</label>
      <input type="number" id="sortPriority" name="sortPriority" min="0" step="1"
             value="{{ (int)($al['sortPriority'] ?? 0) }}"
             style="width:120px; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      <small style="display:block; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">Higher number = higher in lists.</small>
    </div>

    <!-- Actions -->
    <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
      <button type="submit"
              style="padding:0.6rem 1.25rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.9rem; font-weight:600; cursor:pointer;">
        {{ $isEdit ? 'Update album' : 'Create album' }}
      </button>
      @if($isEdit)
        <a href="/admin/gallery/albums/{{ $al['id'] }}/manage"
           style="padding:0.6rem 1rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); text-decoration:none; font-size:0.9rem;">
          Cancel
        </a>
      @else
        <a href="/admin/gallery/albums"
           style="padding:0.6rem 1rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); text-decoration:none; font-size:0.9rem;">
          Cancel
        </a>
      @endif
    </div>

  </form>

</div>

<script>
(function () {
  const fileInput   = document.getElementById('coverImageFile');
  const uploadBtn   = document.getElementById('uploadCoverBtn');
  const urlInput    = document.getElementById('coverImage');
  const statusEl    = document.getElementById('coverUploadStatus');
  const previewWrap = document.getElementById('coverImagePreview');
  const previewImg  = document.getElementById('coverImagePreviewImg');

  if (!fileInput || !uploadBtn || !urlInput || !statusEl) return;

  const csrfMeta  = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = csrfMeta ? csrfMeta.content : '';

  async function uploadCover() {
    if (!fileInput.files || !fileInput.files[0]) {
      statusEl.textContent = 'Please choose an image to upload.';
      statusEl.style.color = 'var(--flag-red)';
      return;
    }

    statusEl.textContent = 'Uploading cover image\u2026';
    statusEl.style.color = 'var(--text-dim)';
    uploadBtn.disabled = true;

    const fd = new FormData();
    fd.append('cover', fileInput.files[0]);

    try {
      const res  = await fetch('/admin/gallery/cover-upload', {
        method:  'POST',
        headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {},
        body:    fd,
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.success) {
        statusEl.textContent = data.message || 'Upload failed. Please try again.';
        statusEl.style.color = 'var(--flag-red)';
      } else {
        urlInput.value       = data.url;
        statusEl.textContent = 'Cover uploaded successfully.';
        statusEl.style.color = 'var(--green-bright)';
        if (previewImg)  previewImg.src             = data.url;
        if (previewWrap) previewWrap.style.display  = 'block';
      }
    } catch {
      statusEl.textContent = 'Network error while uploading cover.';
      statusEl.style.color = 'var(--flag-red)';
    } finally {
      uploadBtn.disabled = false;
    }
  }

  uploadBtn.addEventListener('click', function (e) {
    e.preventDefault();
    uploadCover();
  });

  // Update preview when URL is edited manually
  urlInput.addEventListener('blur', function () {
    const val = urlInput.value.trim();
    if (previewImg && val) {
      previewImg.src = val;
      if (previewWrap) previewWrap.style.display = 'block';
    } else if (previewWrap) {
      previewWrap.style.display = 'none';
    }
  });
})();
</script>

@endsection
