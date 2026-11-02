@extends('layouts.admin')

@section('content')
@php
$pageTitle   = $pageTitle  ?? 'Products';
$activePage  = 'products';
$breadcrumbs = $breadcrumbs ?? [
    ['label' => 'Admin',    'url' => '/admin'],
    ['label' => 'Products'],
];

$list               = is_array($products ?? null) ? $products : [];
$PRODUCT_CATEGORIES = $PRODUCT_CATEGORIES ?? [];
$filters            = $filters ?? [];
$formatPrice        = $formatPrice ?? fn ($v) => 'ZMW ' . number_format((float)$v, 2);

$activeCount = count(array_filter($list, fn ($p) => !empty($p['isActive'])));
@endphp

<!-- Stats row -->
<section class="stats-grid" aria-label="Product stats">
  <article class="stat-card stat-card--green">
    <div class="stat-card__icon"><i class="fas fa-shirt"></i></div>
    <p class="stat-card__label">Total Products</p>
    <p class="stat-card__value">{{ count($list) }}</p>
  </article>
  <article class="stat-card stat-card--orange">
    <div class="stat-card__icon"><i class="fas fa-circle-check"></i></div>
    <p class="stat-card__label">Active</p>
    <p class="stat-card__value">{{ $activeCount }}</p>
  </article>
</section>

<!-- Toolbar -->
<div class="admin-toolbar">
  <form method="GET" action="{{ url('/admin/products') }}" class="admin-toolbar__filters">
    <select name="category" class="admin-select">
      <option value="">All Categories</option>
      @foreach($PRODUCT_CATEGORIES as $c)
        <option value="{{ $c }}"
          {{ ($filters['category'] ?? '') === $c ? 'selected' : '' }}>
          {{ str_replace('-', ' ', $c) }}
        </option>
      @endforeach
    </select>
    <input type="text" name="search"
           value="{{ $filters['search'] ?? '' }}"
           class="admin-input"
           placeholder="Search name or description…" />
    <button type="submit" class="admin-btn admin-btn--primary">
      <i class="fas fa-filter" aria-hidden="true"></i> Apply
    </button>
    <a href="{{ url('/admin/products') }}" class="admin-btn admin-btn--ghost">
      <i class="fas fa-xmark" aria-hidden="true"></i> Clear
    </a>
  </form>

  <a href="/admin/products/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> New Product
  </a>
</div>

<!-- Products table -->
@if(!empty($list))
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Category</th>
          <th>Price</th>
          <th>Stock</th>
          <th>Featured</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($list as $p)
        <tr>
          <td>
            <strong>{{ $p['name'] }}</strong>
            <div class="admin-table__sub">
              <span>Slug: <code>{{ $p['slug'] ?? '' }}</code></span>
            </div>
          </td>
          <td>{{ str_replace('-', ' ', $p['category'] ?? '') }}</td>
          <td>{{ ($formatPrice)($p['price'] ?? 0) }}</td>
          <td>
            @if(empty($p['isActive']))
              <span class="badge badge--red">Inactive</span>
            @elseif(($p['totalStock'] ?? -1) === 0)
              <span class="badge badge--red">Out</span>
            @elseif(isset($p['totalStock']) && $p['totalStock'] <= 5)
              <span class="badge badge--orange">Low ({{ (int)$p['totalStock'] }})</span>
            @else
              <span class="badge badge--green">{{ (int)($p['totalStock'] ?? 0) }}</span>
            @endif
          </td>
          <td>
            @if(!empty($p['featured']))
              <span class="badge badge--gold"><i class="fas fa-star" aria-hidden="true"></i> Yes</span>
            @else
              <span class="badge">No</span>
            @endif
          </td>
          <td>
            @if(!empty($p['isActive']))
              <span class="status-pill status-pill--green">Active</span>
            @else
              <span class="status-pill status-pill--muted">Hidden</span>
            @endif
          </td>
          <td class="row-menu-cell">
            <details class="row-menu">
              <summary class="admin-btn admin-btn--ghost admin-btn--sm row-menu__toggle" aria-label="Actions for {{ $p['name'] }}">
                <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>
              </summary>
              <div class="row-menu__list" role="menu">
                <a href="/admin/products/{{ $p['id'] }}/preview" target="_blank" rel="noopener" role="menuitem">
                  <i class="fas fa-eye" aria-hidden="true"></i> View
                </a>
                <a href="/admin/products/{{ $p['id'] }}/edit" role="menuitem">
                  <i class="fas fa-pen" aria-hidden="true"></i> Edit
                </a>
                <form method="POST"
                      action="/admin/products/{{ $p['id'] }}/delete"
                      onsubmit="return confirm('Hide this product from the shop?');">
                  <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">
                  <button type="submit" class="row-menu__danger" role="menuitem">
                    <i class="fas fa-trash" aria-hidden="true"></i> Delete
                  </button>
                </form>
              </div>
            </details>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

@else

  <div class="admin-empty">
    <i class="fas fa-shirt admin-empty__icon" aria-hidden="true"></i>
    <p class="admin-empty__title">No products yet</p>
    <p class="admin-empty__body">
      Create your first product to start populating the LFS shop.
    </p>
    <a href="/admin/products/create" class="admin-btn admin-btn--primary">
      <i class="fas fa-plus" aria-hidden="true"></i> New Product
    </a>
  </div>

@endif

<style>
.row-menu { position:relative; display:inline-block; }
.row-menu__toggle { list-style:none; cursor:pointer; }
.row-menu__toggle::-webkit-details-marker { display:none; }
.row-menu__list { position:fixed; z-index:50; min-width:150px; background:var(--black-soft,#1a1a1a); border:1px solid var(--border-mid,rgba(255,255,255,0.15)); border-radius:8px; box-shadow:0 12px 30px rgba(0,0,0,0.5); padding:0.35rem; }
.row-menu__list a, .row-menu__list button { display:flex; align-items:center; gap:0.5rem; width:100%; padding:0.5rem 0.65rem; background:transparent; border:none; border-radius:6px; color:var(--off-white,#f5f5f0); font:inherit; font-size:0.85rem; text-align:left; text-decoration:none; cursor:pointer; }
.row-menu__list a:hover, .row-menu__list button:hover { background:rgba(255,255,255,0.08); }
.row-menu__list .row-menu__danger { color:#e0594a; }
.row-menu__list .row-menu__danger:hover { background:rgba(192,57,43,0.2); }
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

@endsection
