/**
 * LFS admin — add/remove per-distance rows on the event form,
 * and show file-selected feedback for route images and banner.
 */
(function () {
  /* ── Distance rows ─────────────────────────────── */
  const list   = document.getElementById('lfsDistanceRows');
  const addBtn = document.getElementById('lfsAddDistance');

  function showFileChosen(fileInput) {
    const file = fileInput.files && fileInput.files[0];
    let notice = fileInput.parentElement.querySelector('.event-form__file-chosen');
    if (!file) {
      if (notice) notice.remove();
      return;
    }
    if (!notice) {
      notice = document.createElement('p');
      notice.className = 'event-form__file-chosen';
      notice.style.cssText = 'margin:0.3rem 0 0; font-size:0.8rem; color:var(--green-bright);';
      fileInput.parentElement.appendChild(notice);
    }
    notice.textContent = '✓ ' + file.name;
  }

  function clearRow(li) {
    li.querySelectorAll('input[name="dist_label[]"]').forEach(function (el) { el.value = ''; });
    li.querySelectorAll('input[name="dist_route_file[]"]').forEach(function (el) { el.value = ''; });
    const h = li.querySelector('input[name="dist_route_existing[]"]');
    if (h) h.value = '';
    const pr = li.querySelector('.event-form__distance-preview');
    if (pr) pr.remove();
    li.querySelectorAll('.event-form__file-chosen').forEach(function (el) { el.remove(); });
  }

  if (list) {
    list.addEventListener('change', function (e) {
      if (e.target.matches('input[name="dist_route_file[]"]')) {
        showFileChosen(e.target);
      }
    });

    if (addBtn) {
      addBtn.addEventListener('click', function () {
        const first = list.querySelector('li[data-distance-row]');
        if (!first) return;
        const clone = first.cloneNode(true);
        clearRow(clone);
        list.appendChild(clone);
      });
    }

    list.addEventListener('click', function (e) {
      const btn = e.target.closest('.lfsRemoveDistance');
      if (!btn) return;
      const li = btn.closest('li[data-distance-row]');
      if (!li) return;
      const items = list.querySelectorAll('li[data-distance-row]');
      if (items.length <= 1) {
        clearRow(li);
        return;
      }
      li.remove();
    });
  }

  /* ── Banner image feedback ─────────────────────── */
  const bannerFile = document.getElementById('bannerImageFile');
  if (bannerFile) {
    bannerFile.addEventListener('change', function () {
      showFileChosen(bannerFile);
    });
  }

  /* ── Brochure PDF feedback ─────────────────────── */
  const brochureFile = document.getElementById('brochurePdfFile');
  if (brochureFile) {
    brochureFile.addEventListener('change', function () {
      showFileChosen(brochureFile);
    });
  }
}());
