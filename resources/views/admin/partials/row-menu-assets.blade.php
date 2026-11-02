<style>
.row-menu { position:relative; display:inline-block; }
.row-menu__toggle { list-style:none; cursor:pointer; }
.row-menu__toggle::-webkit-details-marker { display:none; }
.row-menu__list { position:fixed; z-index:50; min-width:150px; background:var(--black-soft,#1a1a1a); border:1px solid var(--border-mid,rgba(255,255,255,0.15)); border-radius:8px; box-shadow:0 12px 30px rgba(0,0,0,0.5); padding:0.35rem; }
.row-menu__list a, .row-menu__list button { display:flex; align-items:center; gap:0.5rem; width:100%; padding:0.5rem 0.65rem; background:transparent; border:none; border-radius:6px; color:var(--off-white,#f5f5f0); font:inherit; font-size:0.85rem; text-align:left; text-decoration:none; cursor:pointer; }
.row-menu__list a:hover, .row-menu__list button:hover { background:rgba(255,255,255,0.08); }
.row-menu__list .row-menu__danger { color:#e0594a; }
.row-menu__list .row-menu__danger:hover { background:rgba(192,57,43,0.2); }
.row-menu__list button:disabled { opacity:0.45; cursor:not-allowed; background:transparent; }
</style>
<script>
document.addEventListener('toggle', function (e) {
  var d = e.target;
  if (!d.classList || !d.classList.contains('row-menu') || !d.open) return;
  document.querySelectorAll('details.row-menu[open]').forEach(function (o) { if (o !== d) o.open = false; });
  var list = d.querySelector('.row-menu__list'), r = d.getBoundingClientRect();
  var h = list.offsetHeight, w = list.offsetWidth;
  list.style.top = (r.bottom + h + 8 > window.innerHeight ? r.top - h - 4 : r.bottom + 4) + 'px';
  list.style.left = Math.max(8, r.right - w) + 'px';
}, true);
document.addEventListener('click', function (e) {
  document.querySelectorAll('details.row-menu[open]').forEach(function (o) { if (!o.contains(e.target)) o.open = false; });
});
window.addEventListener('scroll', function () {
  document.querySelectorAll('details.row-menu[open]').forEach(function (o) { o.open = false; });
}, true);
</script>
