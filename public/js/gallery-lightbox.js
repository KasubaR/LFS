/* gallery-lightbox.js — site gallery album image/video lightbox */

var lbIdx = 0;

function openLightbox(idx) {
  lbIdx = idx;
  renderLightbox();
  var overlay = document.getElementById('lbOverlay');
  if (overlay) {
    overlay.classList.remove('is-hidden');
  }
  document.addEventListener('keydown', lbKeydown);
}

function closeLightbox() {
  var overlay = document.getElementById('lbOverlay');
  if (overlay) {
    overlay.classList.add('is-hidden');
  }
  var v = overlay.querySelector('video');
  if (v) v.pause();
  document.removeEventListener('keydown', lbKeydown);
}

function lbPrev() { if (lbIdx > 0) { lbIdx--; renderLightbox(); } }
function lbNext() { if (lbIdx < LB_MEDIA.length - 1) { lbIdx++; renderLightbox(); } }

function lbKeydown(e) {
  if (e.key === 'ArrowLeft')  lbPrev();
  if (e.key === 'ArrowRight') lbNext();
  if (e.key === 'Escape')     closeLightbox();
}

function renderLightbox() {
  var item    = LB_MEDIA[lbIdx];
  var content = document.getElementById('lbContent');
  if (item.type === 'video') {
    content.innerHTML = '<video src="' + item.video + '" controls></video>';
  } else {
    content.innerHTML = '<img src="' + item.large + '" alt="' + item.caption.replace(/"/g, '&quot;') + '">';
  }
  document.getElementById('lbCounter').textContent = (lbIdx + 1) + ' / ' + LB_MEDIA.length;
  document.getElementById('lbCaption').textContent = item.caption;
  document.querySelector('.lb-nav--prev').style.opacity = lbIdx === 0 ? '0.3' : '1';
  document.querySelector('.lb-nav--next').style.opacity = lbIdx === LB_MEDIA.length - 1 ? '0.3' : '1';
}

document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('lbOverlay');
  if (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === this) closeLightbox();
    });
  }
});
