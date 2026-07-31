@extends('layouts.admin')

@section('content')
@php
$pageTitle   = 'Gallery — Upload Media';
$activePage  = 'gallery';
$breadcrumbs = [
    ['label' => 'Gallery', 'url' => '/admin/gallery'],
    ['label' => 'Albums',  'url' => '/admin/gallery/albums'],
    ['label' => 'Upload'],
];

$albums        = $albums        ?? [];
$selectedAlbum = $selectedAlbum ?? '';
@endphp

<div style="max-width:900px;">

  <!-- ══════════════════════════════════════════════
       HEADER
  ════════════════════════════════════════════════ -->
  <div style="margin-bottom:1.75rem;">
    <p style="color:var(--text-dim); font-size:0.9rem; margin-top:0.25rem;">
      Upload photos or videos to an album. Images are auto-optimised and converted to WebP.
      Max 5 MB per image, 200 MB per video, 50 files per batch.
    </p>
  </div>

  <!-- ══════════════════════════════════════════════
       ALBUM SELECTOR
  ════════════════════════════════════════════════ -->
  <div class="upload-section" style="margin-bottom:1.5rem;">
    <label class="form-label" for="albumSelect">
      <i class="fas fa-folder-open" style="color:var(--flag-green);"></i>
      Target Album <span style="color:var(--flag-red);">*</span>
    </label>
    <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
      <select id="albumSelect" required
              style="flex:1; min-width:200px; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;">
        <option value="">— Select an album —</option>
        @foreach($albums as $alb)
          <option value="{{ $alb['id'] }}"
            {{ ($selectedAlbum === (string)($alb['id'] ?? '')) ? 'selected' : '' }}>
            {{ $alb['title'] }} ({{ $alb['category'] ?? '' }})
          </option>
        @endforeach
      </select>
      <a href="/admin/gallery/albums/create"
         style="white-space:nowrap; display:inline-flex; align-items:center; gap:0.4rem; padding:0.6rem 1rem; background:rgba(255,255,255,0.06); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); text-decoration:none; font-size:0.85rem;">
        <i class="fas fa-plus"></i> New Album
      </a>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       UPLOAD TYPE TABS
  ════════════════════════════════════════════════ -->
  <div class="upload-tabs" style="margin-bottom:1.5rem;">
    <button class="upload-tab active" id="tabPhotos" onclick="switchTab('photos')">
      <i class="fas fa-image"></i> Photos
    </button>
    <button class="upload-tab" id="tabVideos" onclick="switchTab('videos')">
      <i class="fas fa-video"></i> Videos
    </button>
  </div>

  <!-- ══════════════════════════════════════════════
       PHOTO UPLOAD PANEL
  ════════════════════════════════════════════════ -->
  <div id="panelPhotos">

    <div id="photoDropzone" class="dropzone" role="button" tabindex="0"
         aria-label="Drop photos here or click to browse"
         ondragover="handleDragOver(event,'photoDropzone')"
         ondragleave="handleDragLeave('photoDropzone')"
         ondrop="handleDrop(event,'photo')"
         onclick="document.getElementById('photoInput').click()"
         onkeydown="if(event.key==='Enter'||event.key===' ')document.getElementById('photoInput').click()">
      <i class="fas fa-cloud-arrow-up dropzone__icon"></i>
      <p class="dropzone__title">Drag &amp; drop photos here</p>
      <p class="dropzone__sub">JPG, PNG, WEBP &middot; max 5 MB each &middot; 50 files per batch</p>
      <button type="button" class="dropzone__browse-btn"
              onclick="event.stopPropagation(); document.getElementById('photoInput').click()">
        <i class="fas fa-folder-open"></i> Browse Files
      </button>
      <input type="file" id="photoInput" multiple
             accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
             style="display:none;" onchange="handleFileSelect(event,'photo')" />
    </div>

    <div id="photoPreviewGrid" class="preview-grid" style="display:none;"></div>

    <div id="photoControls" style="display:none; margin-top:1.25rem; align-items:center; gap:0.75rem; flex-wrap:wrap;">
      <span id="photoCount" style="font-size:0.85rem; color:var(--text-dim);"></span>
      <button type="button" class="btn-upload" id="btnUploadPhotos" onclick="startUpload('photo')">
        <i class="fas fa-upload"></i> Upload Photos
      </button>
      <button type="button" class="btn-clear" onclick="clearFiles('photo')">
        <i class="fas fa-xmark"></i> Clear All
      </button>
    </div>

  </div>

  <!-- ══════════════════════════════════════════════
       VIDEO UPLOAD PANEL
  ════════════════════════════════════════════════ -->
  <div id="panelVideos" style="display:none;">

    <div id="videoDropzone" class="dropzone" role="button" tabindex="0"
         aria-label="Drop video here or click to browse"
         ondragover="handleDragOver(event,'videoDropzone')"
         ondragleave="handleDragLeave('videoDropzone')"
         ondrop="handleDrop(event,'video')"
         onclick="document.getElementById('videoInput').click()"
         onkeydown="if(event.key==='Enter'||event.key===' ')document.getElementById('videoInput').click()">
      <i class="fas fa-film dropzone__icon"></i>
      <p class="dropzone__title">Drag &amp; drop a video here</p>
      <p class="dropzone__sub">MP4, MOV, WEBM &middot; max 200 MB</p>
      <button type="button" class="dropzone__browse-btn"
              onclick="event.stopPropagation(); document.getElementById('videoInput').click()">
        <i class="fas fa-folder-open"></i> Browse Files
      </button>
      <input type="file" id="videoInput"
             accept=".mp4,.mov,.webm,video/mp4,video/quicktime,video/webm"
             style="display:none;" onchange="handleFileSelect(event,'video')" />
    </div>

    <div id="videoPreviewGrid" class="preview-grid" style="display:none;"></div>

    <div id="videoControls" style="display:none; margin-top:1.25rem; align-items:center; gap:0.75rem; flex-wrap:wrap;">
      <span id="videoCount" style="font-size:0.85rem; color:var(--text-dim);"></span>
      <button type="button" class="btn-upload" id="btnUploadVideos" onclick="startUpload('video')">
        <i class="fas fa-upload"></i> Upload Video
      </button>
      <button type="button" class="btn-clear" onclick="clearFiles('video')">
        <i class="fas fa-xmark"></i> Clear
      </button>
    </div>

  </div>

  <!-- ══════════════════════════════════════════════
       UPLOAD PROGRESS
  ════════════════════════════════════════════════ -->
  <div id="uploadProgress" style="display:none; margin-top:1.5rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-dim); gap:0.5rem;">
      <div style="display:flex; align-items:center; gap:0.4rem;">
        <span id="progressSpinner" class="lfs-spinner lfs-spinner--sm is-hidden" aria-hidden="true"></span>
        <span id="progressLabel">Uploading…</span>
      </div>
      <span id="progressPct">0%</span>
    </div>
    <div style="background:var(--black-panel); border-radius:99px; height:8px; overflow:hidden;">
      <div id="progressBar" style="height:100%; background:var(--flag-green); border-radius:99px; width:0%; transition:width 200ms ease;"></div>
    </div>
    <div id="uploadLog" style="margin-top:0.75rem; font-size:0.8rem; color:var(--text-dim); max-height:120px; overflow-y:auto;"></div>
  </div>

</div><!-- /max-width wrapper -->


<!-- ══════════════════════════════════════════════
     STYLES
════════════════════════════════════════════════ -->
<style>
.form-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text-dim);
  margin-bottom: 0.5rem;
}

/* Tabs */
.upload-tabs { display: flex; gap: 0.5rem; }
.upload-tab {
  display: inline-flex; align-items: center; gap: 0.4rem;
  padding: 0.5rem 1.1rem;
  border-radius: 8px 8px 0 0;
  border: 1px solid var(--border-mid); border-bottom: none;
  background: var(--black-panel);
  color: var(--text-dim);
  font-family: var(--font-body); font-size: 0.85rem; font-weight: 600;
  cursor: pointer;
  transition: background var(--trans-fast), color var(--trans-fast);
}
.upload-tab.active { background: var(--black-soft); color: var(--off-white); border-color: var(--flag-green); }

/* Drop zone */
.dropzone {
  border: 2px dashed var(--border-mid);
  border-radius: 0 12px 12px 12px;
  padding: 3rem 2rem;
  text-align: center;
  cursor: pointer;
  transition: border-color var(--trans-fast), background var(--trans-fast);
  background: var(--black-soft);
  user-select: none;
}
.dropzone:hover,
.dropzone.drag-over { border-color: var(--flag-green); background: rgba(25,138,78,0.05); }
.dropzone__icon { font-size: 2.8rem; color: var(--flag-green); margin-bottom: 0.75rem; display: block; }
.dropzone__title { font-size: 1.05rem; font-weight: 600; color: var(--off-white); margin-bottom: 0.4rem; }
.dropzone__sub { font-size: 0.8rem; color: var(--text-dim); margin-bottom: 1rem; }
.dropzone__browse-btn {
  display: inline-flex; align-items: center; gap: 0.4rem;
  padding: 0.55rem 1.25rem;
  background: var(--flag-green); color: #fff;
  border: none; border-radius: 8px;
  font-family: var(--font-body); font-size: 0.85rem; font-weight: 600;
  cursor: pointer;
  transition: background var(--trans-fast);
}
.dropzone__browse-btn:hover { background: var(--green); }

/* Preview grid */
.preview-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
  gap: 0.6rem;
  margin-top: 1rem;
  max-height: 400px;
  overflow-y: auto;
  padding-right: 0.25rem;
}
.preview-item {
  position: relative; aspect-ratio: 1;
  border-radius: 8px; overflow: hidden;
  background: var(--black-panel);
  border: 1px solid var(--border-subtle);
}
.preview-item img,
.preview-item video { width: 100%; height: 100%; object-fit: cover; }
.preview-item__remove {
  position: absolute; top: 0.25rem; right: 0.25rem;
  width: 22px; height: 22px; border-radius: 50%;
  background: rgba(192,57,43,0.85); border: none;
  color: #fff; font-size: 0.65rem;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; opacity: 0;
  transition: opacity var(--trans-fast);
}
.preview-item:hover .preview-item__remove { opacity: 1; }
.preview-item__status {
  position: absolute; bottom: 0; left: 0; right: 0;
  font-size: 0.65rem; text-align: center;
  padding: 0.2rem 0.3rem;
  background: rgba(0,0,0,0.65); color: #fff;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.preview-item__status.ok  { background: rgba(25,138,78,0.85); }
.preview-item__status.err { background: rgba(192,57,43,0.85); }

/* Upload buttons */
.btn-upload {
  display: inline-flex; align-items: center; gap: 0.5rem;
  padding: 0.6rem 1.4rem;
  background: var(--flag-green); color: #fff;
  border: none; border-radius: 8px;
  font-family: var(--font-body); font-size: 0.9rem; font-weight: 700;
  cursor: pointer;
  transition: background var(--trans-fast);
}
.btn-upload:hover     { background: var(--green); }
.btn-upload:disabled  { opacity: 0.5; cursor: not-allowed; }

.btn-clear {
  display: inline-flex; align-items: center; gap: 0.4rem;
  padding: 0.6rem 1rem;
  background: rgba(192,57,43,0.12); color: var(--flag-red);
  border: 1px solid rgba(192,57,43,0.3); border-radius: 8px;
  font-family: var(--font-body); font-size: 0.85rem;
  cursor: pointer;
  transition: background var(--trans-fast);
}
.btn-clear:hover { background: rgba(192,57,43,0.25); }
</style>


<!-- ══════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════════ -->
<script src="{{ asset('admin/js/gallery-upload.js') }}"></script>

@endsection
