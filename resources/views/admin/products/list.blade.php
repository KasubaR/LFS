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

<!-- Online sell-out threshold -->
<form method="post" action="{{ url('/admin/products/settings') }}" class="admin-form-card" style="margin-bottom:1rem;">
  @csrf
  <div class="form-group{{ $errors->has('online_sellout_threshold') ? ' form-group--error' : '' }}" style="margin-bottom:.5rem;">
    <label for="online_sellout_threshold" class="admin-label">Online sell-out threshold (per size)</label>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
      <input id="online_sellout_threshold" name="online_sellout_threshold" type="number" min="0" step="1"
             value="{{ old('online_sellout_threshold', $onlineSelloutThreshold ?? 20) }}" class="admin-input" style="max-width:8rem;">
      <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Save</button>
    </div>
    <p class="form-group__hint">
      When a size has this many or fewer left, the website shows it as Sold Out. Those units are kept for offline sales.
      The Stock column below shows the real stock.
    </p>
    @error('online_sellout_threshold')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
  </div>
</form>

<!-- Toolbar -->
<div class="admin-toolbar">
  <form method="GET" action="{{ url('/admin/products') }}" class="admin-toolbar__filters">
    <select name="category" class="admin-select">
      <option value="">All Categories</option>
      @foreach($PRODUCT_CATEGORIES as $slug => $label)
        <option value="{{ $slug }}"
          {{ ($filters['category'] ?? '') === $slug ? 'selected' : '' }}>
          {{ $label }}
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

  <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
    @if(!empty($adminCanRead['orders']))
    <a href="/admin/orders/payments" class="admin-btn admin-btn--primary">
      <i class="fas fa-credit-card" aria-hidden="true"></i> Payments
    </a>
    @endif
    <a href="/admin/products/categories" class="admin-btn admin-btn--ghost">
      <i class="fas fa-tags" aria-hidden="true"></i> Categories
    </a>
    <a href="/admin/products/size-guides" class="admin-btn admin-btn--ghost">
      <i class="fas fa-ruler-horizontal" aria-hidden="true"></i> Size guides
    </a>
    <a href="/admin/products/create" class="admin-btn admin-btn--primary">
      <i class="fas fa-plus" aria-hidden="true"></i> New Product
    </a>
  </div>
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

@include('admin.partials.row-menu-assets')

@endsection
