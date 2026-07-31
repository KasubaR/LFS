'use strict';

/* Global drag/drop guards */
document.addEventListener(
  'dragover',
  function (e) {
    e.preventDefault();
  },
  false,
);

document.addEventListener(
  'drop',
  function (e) {
    // Let our dropzones handle drops; anywhere else, prevent browser from opening files
    if (!e.target.closest || !e.target.closest('.dropzone')) {
      e.preventDefault();
      e.stopPropagation();
    }
  },
  false,
);

/* State */
const state = { photo: [], video: [] };

/* Constants */
const PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const VIDEO_MAX_BYTES = 200 * 1024 * 1024;
const PHOTO_MAX_COUNT = 50;
const ALLOWED_PHOTOS = ['image/jpeg', 'image/png', 'image/webp'];
const ALLOWED_VIDEOS = ['video/mp4', 'video/quicktime', 'video/webm'];

/* Tab switching */
function switchTab(type) {
  document.getElementById('panelPhotos').style.display = type === 'photos' ? 'block' : 'none';
  document.getElementById('panelVideos').style.display = type === 'videos' ? 'block' : 'none';
  document.getElementById('tabPhotos').classList.toggle('active', type === 'photos');
  document.getElementById('tabVideos').classList.toggle('active', type === 'videos');
}

/* Drag events */
function handleDragOver(e, zoneId) {
  e.preventDefault();
  const zone = document.getElementById(zoneId);
  if (zone) zone.classList.add('drag-over');
}

function handleDragLeave(zoneId) {
  const zone = document.getElementById(zoneId);
  if (zone) zone.classList.remove('drag-over');
}

function handleDrop(e, type) {
  e.preventDefault();
  const zoneId = type === 'photo' ? 'photoDropzone' : 'videoDropzone';
  const zone = document.getElementById(zoneId);
  if (zone) zone.classList.remove('drag-over');
  if (e.dataTransfer?.files) {
    addFiles(Array.from(e.dataTransfer.files), type);
  }
}

function handleFileSelect(e, type) {
  if (e.target?.files) {
    addFiles(Array.from(e.target.files), type);
  }
}

/* File validation & adding */
function addFiles(files, type) {
  const allowed = type === 'photo' ? ALLOWED_PHOTOS : ALLOWED_VIDEOS;
  const maxBytes = type === 'photo' ? PHOTO_MAX_BYTES : VIDEO_MAX_BYTES;
  const errors = [];

  files.forEach((f) => {
    if (!allowed.includes(f.type)) {
      errors.push(f.name + ': unsupported format');
      return;
    }
    if (f.size > maxBytes) {
      errors.push(
        f.name + ': exceeds size limit (' + maxBytes / 1024 / 1024 + ' MB)',
      );
      return;
    }
    if (type === 'photo' && state.photo.length >= PHOTO_MAX_COUNT) {
      errors.push('Maximum ' + PHOTO_MAX_COUNT + ' photos per batch');
      return;
    }
    if (type === 'video' && state.video.length >= 1) {
      errors.push('Upload one video at a time');
      return;
    }
    state[type].push(f);
  });

  if (errors.length) {
    // eslint-disable-next-line no-alert
    alert('Upload issues:\n' + errors.join('\n'));
  }
  renderPreviews(type);
  updateControls(type);
}

/* Preview rendering */
function renderPreviews(type) {
  const grid = document.getElementById(
    type === 'photo' ? 'photoPreviewGrid' : 'videoPreviewGrid',
  );
  if (!grid) return;

  grid.innerHTML = '';
  state[type].forEach((file, i) => {
    const item = document.createElement('div');
    item.className = 'preview-item';
    item.id = type + '_item_' + i;

    if (type === 'photo') {
      const img = document.createElement('img');
      img.src = URL.createObjectURL(file);
      img.alt = file.name;
      item.appendChild(img);
    } else {
      const vid = document.createElement('video');
      vid.src = URL.createObjectURL(file);
      vid.muted = true;
      vid.preload = 'metadata';
      item.appendChild(vid);
    }

    const status = document.createElement('div');
    status.className = 'preview-item__status';
    status.textContent = shortenName(file.name);
    item.appendChild(status);

    const rm = document.createElement('button');
    rm.className = 'preview-item__remove';
    rm.innerHTML = '<i class="fas fa-xmark"></i>';
    rm.title = 'Remove ' + file.name;
    rm.onclick = (ev) => {
      ev.stopPropagation();
      removeFile(type, i);
    };
    item.appendChild(rm);

    grid.appendChild(item);
  });
  grid.style.display = state[type].length ? 'grid' : 'none';
}

function shortenName(name) {
  return name.length > 18
    ? name.slice(0, 14) + '...' + name.split('.').pop()
    : name;
}

function removeFile(type, idx) {
  state[type].splice(idx, 1);
  renderPreviews(type);
  updateControls(type);
}

function clearFiles(type) {
  state[type] = [];
  renderPreviews(type);
  updateControls(type);
  const input = document.getElementById(
    type === 'photo' ? 'photoInput' : 'videoInput',
  );
  if (input) input.value = '';
}

function updateControls(type) {
  const count = state[type].length;
  const controlsId = type === 'photo' ? 'photoControls' : 'videoControls';
  const countId = type === 'photo' ? 'photoCount' : 'videoCount';
  const controls = document.getElementById(controlsId);
  if (controls) {
    controls.style.display = count > 0 ? 'flex' : 'none';
  }
  const countEl = document.getElementById(countId);
  if (countEl) {
    countEl.textContent =
      count +
      (type === 'photo'
        ? ' photo' + (count !== 1 ? 's' : '')
        : ' video') +
      ' selected';
  }
}

/* Upload */
async function startUpload(type) {
  const albumSelect = document.getElementById('albumSelect');
  const albumId = albumSelect ? albumSelect.value : '';
  if (!albumId) {
    // eslint-disable-next-line no-alert
    alert('Please select an album first.');
    return;
  }
  if (state[type].length === 0) {
    // eslint-disable-next-line no-alert
    alert('No files selected.');
    return;
  }

  const btnId = type === 'photo' ? 'btnUploadPhotos' : 'btnUploadVideos';
  const btn = document.getElementById(btnId);
  if (btn) btn.disabled = true;

  const progress = document.getElementById('uploadProgress');
  const progressBar = document.getElementById('progressBar');
  const progressPct = document.getElementById('progressPct');
  const progressLabel = document.getElementById('progressLabel');
  const progressSpinner = document.getElementById('progressSpinner');
  const uploadLog = document.getElementById('uploadLog');

  if (progress) progress.style.display = 'block';
  if (progressBar) progressBar.style.width = '0%';
  if (progressPct) progressPct.textContent = '0%';
  if (uploadLog) uploadLog.innerHTML = '';
  if (progressSpinner) progressSpinner.classList.remove('is-hidden');

  const files = state[type];
  let done = 0;

  for (const file of files) {
    if (progressLabel) progressLabel.textContent = 'Uploading ' + file.name + '...';
    const fd = new FormData();
    fd.append('albumId', albumId);
    fd.append('type', type);
    fd.append('file', file);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const res = await fetch('/admin/gallery/upload', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: fd,
      });
      const data = await res.json();

      done += 1;
      const pct = Math.round((done / files.length) * 100);
      if (progressBar) progressBar.style.width = pct + '%';
      if (progressPct) progressPct.textContent = pct + '%';

      if (uploadLog) {
        const logLine = document.createElement('div');
        if (res.ok) {
          logLine.innerHTML =
            '<i class="fas fa-check" style="color:var(--flag-green);"></i> ' +
            file.name;
          const statusEl = document.querySelector(
            '#' + type + '_item_' + (done - 1) + ' .preview-item__status',
          );
          if (statusEl) {
            statusEl.textContent = 'ok';
            statusEl.className = 'preview-item__status ok';
          }
        } else {
          logLine.innerHTML =
            '<i class="fas fa-xmark" style="color:var(--flag-red);"></i> ' +
            file.name +
            ': ' +
            (data.message || 'Error');
          const statusEl = document.querySelector(
            '#' + type + '_item_' + (done - 1) + ' .preview-item__status',
          );
          if (statusEl) {
            statusEl.textContent = 'Error';
            statusEl.className = 'preview-item__status err';
          }
        }
        uploadLog.appendChild(logLine);
        uploadLog.scrollTop = uploadLog.scrollHeight;
      }
    } catch (err) {
      done += 1;
      if (uploadLog) {
        const logLine = document.createElement('div');
        logLine.innerHTML =
          '<i class="fas fa-xmark" style="color:var(--flag-red);"></i> ' +
          file.name +
          ': Network error';
        uploadLog.appendChild(logLine);
      }
    }
  }

  if (progressLabel) {
    progressLabel.textContent =
      'Upload complete (' + done + '/' + files.length + ')';
  }
  if (btn) btn.disabled = false;
  if (progressSpinner) progressSpinner.classList.add('is-hidden');

  if (done === files.length && albumSelect) {
    setTimeout(() => {
      window.location.href =
        '/admin/gallery/albums/' + albumSelect.value + '/manage';
    }, 1200);
  }
}

/* Expose functions for inline handlers */
window.switchTab = switchTab;
window.handleDragOver = handleDragOver;
window.handleDragLeave = handleDragLeave;
window.handleDrop = handleDrop;
window.handleFileSelect = handleFileSelect;
window.startUpload = startUpload;
window.clearFiles = clearFiles;

