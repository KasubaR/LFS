@extends('layouts.app')

@section('content')
@php
/* ============================================================
   LFS — Lusaka Fitness Squad
   views/pages/shop.php

   Variables provided by the shop controller:
     $products, $total, $pages, $currentPage,
     $filters (array), $categoryOptions, $genderOptions,
     $cartCount
   ============================================================ */

// Inject page-level stylesheet into the layout
@endphp

<!-- ══════════════════════════════════════════
     PAGE HEADER
     ══════════════════════════════════════════ -->
<section class="shop-header">
  <div class="shop-header__inner">

    <!-- Breadcrumb -->
    <nav class="shop-breadcrumb" aria-label="Breadcrumb">
      <ol itemscope itemtype="https://schema.org/BreadcrumbList">
        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
          <a itemprop="item" href="{{ url('/') }}"><span itemprop="name">Home</span></a>
          <meta itemprop="position" content="1">
          <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </li>
        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
          <span itemprop="name" aria-current="page">Shop</span>
          <meta itemprop="position" content="2">
        </li>
      </ol>
    </nav>

    <div class="shop-header__copy">
      <h1 class="text-display-lg">Shop LFS Merchandise</h1>
      <p class="shop-header__desc">
        High-quality running gear and regalia for Lusaka Fitness Squad members.<br>
        Look the part. Run the part.
      </p>
    </div>

    <!-- Zambian flag accent -->
    <div class="flag-stripe flag-stripe--on-black mt-6" aria-hidden="true">
      <span></span><span></span><span></span><span></span>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     SHOP LAYOUT — filters sidebar + grid
     ══════════════════════════════════════════ -->
<section class="shop-layout">
  <div class="shop-layout__inner">

    <!-- ── FILTER SIDEBAR ── -->
    <aside class="shop-filters" id="shop-filters" aria-label="Product filters">

      <div class="shop-filters__header">
        <h2 class="shop-filters__title">
          <i class="fas fa-sliders-h" aria-hidden="true"></i>
          Filters
        </h2>
        <button class="shop-filters__clear js-clear-filters" type="button" aria-label="Clear all filters">
          Clear All
        </button>
      </div>

      <form class="shop-filters__form" id="shop-filter-form" method="GET" action="{{ url('/shop') }}">

        <!-- Sort -->
        <div class="shop-filters__group">
          <label class="shop-filters__label" for="filter-sort">Sort By</label>
          <div class="shop-filters__select-wrap">
            <select id="filter-sort" name="sort" class="shop-filters__select" aria-label="Sort products">
              <option value="latest"     {{ ($filters['sort'] ?? '') === 'latest'     ? 'selected' : '' }}>Latest</option>
              <option value="popular"    {{ ($filters['sort'] ?? '') === 'popular'    ? 'selected' : '' }}>Most Popular</option>
              <option value="price-asc"  {{ ($filters['sort'] ?? '') === 'price-asc'  ? 'selected' : '' }}>Price: Low → High</option>
              <option value="price-desc" {{ ($filters['sort'] ?? '') === 'price-desc' ? 'selected' : '' }}>Price: High → Low</option>
            </select>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
          </div>
        </div>

        <!-- Category -->
        <div class="shop-filters__group">
          <p class="shop-filters__label">Category</p>
          <ul class="shop-filters__check-list" role="list">
            @foreach($categoryOptions ?? [] as $cat)
            <li>
              <label class="shop-filters__check-label">
                <input
                  type="radio"
                  name="category"
                  value="{{ $cat['value'] }}"
                  class="shop-filters__radio"
                  {{ ($filters['category'] ?? '') === $cat['value'] ? 'checked' : '' }}
                  aria-label="Filter by {{ $cat['label'] }}"
                >
                <span class="shop-filters__check-text">{{ $cat['label'] }}</span>
              </label>
            </li>
            @endforeach
          </ul>
        </div>

        <!-- Gender -->
        <div class="shop-filters__group">
          <p class="shop-filters__label">Gender</p>
          <ul class="shop-filters__check-list" role="list">
            @foreach($genderOptions ?? [] as $g)
            <li>
              <label class="shop-filters__check-label">
                <input
                  type="radio"
                  name="gender"
                  value="{{ $g['value'] }}"
                  class="shop-filters__radio"
                  {{ ($filters['gender'] ?? '') === $g['value'] ? 'checked' : '' }}
                  aria-label="Filter by {{ $g['label'] }}"
                >
                <span class="shop-filters__check-text">{{ $g['label'] }}</span>
              </label>
            </li>
            @endforeach
          </ul>
        </div>

        <!-- Size -->
        <div class="shop-filters__group">
          <p class="shop-filters__label">Size</p>
          <div class="shop-filters__size-grid" role="group" aria-label="Size filter">
            @foreach(['S', 'M', 'L', 'XL', 'XXL'] as $s)
            <label class="shop-filters__size-chip {{ ($filters['size'] ?? '') === $s ? 'is-active' : '' }}">
              <input
                type="radio"
                name="size"
                value="{{ $s }}"
                class="sr-only"
                {{ ($filters['size'] ?? '') === $s ? 'checked' : '' }}
                aria-label="Size {{ $s }}"
              >
              {{ $s }}
            </label>
            @endforeach
          </div>
        </div>

        <!-- Hidden page reset on filter -->
        <input type="hidden" name="page" value="1" id="filter-page">

        <button type="submit" class="btn btn-primary shop-filters__submit">
          <i class="fas fa-search" aria-hidden="true"></i>
          Apply Filters
        </button>

      </form>
    </aside><!-- /.shop-filters -->

    <!-- ── PRODUCT GRID AREA ── -->
    <div class="shop-main">

      <!-- Mobile filter toggle -->
      <div class="shop-main__toolbar">
        @php
        $activeFilterCount = count(array_filter([
          $filters['category'] ?? '',
          $filters['gender']   ?? '',
          $filters['size']     ?? '',
        ]));
        @endphp
        <button class="shop-filters__mobile-toggle btn btn-outline" id="js-filter-toggle" aria-expanded="false" aria-controls="shop-filters" type="button">
          <i class="fas fa-sliders-h" aria-hidden="true"></i>
          Filters
          @if($activeFilterCount > 0)
            <span class="shop-filters__badge">{{ $activeFilterCount }}</span>
          @endif
        </button>

        <p class="shop-main__count">
          Showing
          <strong>
            {{ (($currentPage - 1) * 12) + 1 }}–{{ min($currentPage * 12, $total) }}
          </strong>
          of <strong>{{ $total }}</strong> products
        </p>
      </div>

      <!-- ── PRODUCT GRID ── -->
      @if(count($products) > 0)
        <div class="product-grid" id="product-grid">
          @foreach($products as $product)
            @include('partials.productCard', ['product' => $product])
          @endforeach
        </div>

        <!-- ── PAGINATION ── -->
        @if($pages > 1)
        @php
        // Build a query string for a given page number, preserving active filters
        function buildShopUrl(int $p, array $filters): string {
          $params = array_filter(array_merge($filters, ['page' => $p]));
          return url('/shop').'?'.http_build_query($params);
        }
        @endphp
        <nav class="shop-pagination" aria-label="Pagination">

          @if($currentPage > 1)
            <a href="{{ buildShopUrl($currentPage - 1, $filters) }}" class="shop-pagination__btn shop-pagination__btn--prev" aria-label="Previous page">
              <i class="fas fa-chevron-left" aria-hidden="true"></i>
              Prev
            </a>
          @endif

          <div class="shop-pagination__pages" role="list">
            @for($p = 1; $p <= $pages; $p++)
              @if($p === 1 || $p === $pages || ($p >= $currentPage - 2 && $p <= $currentPage + 2))
                <a
                  href="{{ buildShopUrl($p, $filters) }}"
                  class="shop-pagination__page{{ $p === $currentPage ? ' is-active' : '' }}"
                  aria-label="Page {{ $p }}"
                  aria-current="{{ $p === $currentPage ? 'page' : 'false' }}"
                  role="listitem"
                >
                  {{ $p }}
                </a>
              @elseif($p === $currentPage - 3 || $p === $currentPage + 3)
                <span class="shop-pagination__ellipsis" aria-hidden="true">…</span>
              @endif
            @endfor
          </div>

          @if($currentPage < $pages)
            <a href="{{ buildShopUrl($currentPage + 1, $filters) }}" class="shop-pagination__btn shop-pagination__btn--next" aria-label="Next page">
              Next
              <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
          @endif

        </nav>
        @endif

      @else

        <!-- ── EMPTY STATE ── -->
        <div class="shop-empty" role="status" aria-live="polite">
          <div class="shop-empty__icon" aria-hidden="true">
            <i class="fas fa-tshirt"></i>
          </div>
          <h2 class="shop-empty__heading">No products found</h2>
          <p class="shop-empty__desc">
            @if(!empty($filters['category']) || !empty($filters['gender']) || !empty($filters['size']))
              No products match your current filters. Try adjusting or clearing them.
            @else
              No products are available right now. Check back soon!
            @endif
          </p>
          @if(!empty($filters['category']) || !empty($filters['gender']) || !empty($filters['size']))
            <a href="{{ url('/shop') }}" class="btn btn-primary mt-6">
              <i class="fas fa-times" aria-hidden="true"></i>
              Clear Filters
            </a>
          @endif
        </div>

      @endif

    </div><!-- /.shop-main -->
  </div><!-- /.shop-layout__inner -->
</section>

<!-- ══════════════════════════════════════════
     QUICK VIEW MODAL
     ══════════════════════════════════════════ -->
<div
  class="shop-modal"
  id="quick-view-modal"
  role="dialog"
  aria-modal="true"
  aria-label="Quick product view"
  aria-hidden="true"
>
  <div class="shop-modal__backdrop js-modal-close"></div>
  <div class="shop-modal__panel">

    <button class="shop-modal__close js-modal-close" aria-label="Close quick view" type="button">
      <i class="fas fa-times" aria-hidden="true"></i>
    </button>

    <div class="shop-modal__body" id="quick-view-content">
      <!-- Populated via JS fetch to /shop/product/:slug?modal=1 or inline data -->
      <div class="shop-modal__loading" aria-label="Loading">
        <span class="live-dot"></span>
        <span class="live-dot delay-1"></span>
        <span class="live-dot delay-2"></span>
      </div>
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════
     CART TOAST NOTIFICATION
     ══════════════════════════════════════════ -->
<div class="lfs-toast" id="cart-toast" role="alert" aria-live="assertive" aria-atomic="true">
  <i class="fas fa-check-circle" aria-hidden="true"></i>
  <span id="cart-toast-msg">Item added to cart</span>
</div>

<!-- ══════════════════════════════════════════
     STRUCTURED DATA
     ══════════════════════════════════════════ -->
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "ItemList",
  "name": "LFS Shop Merchandise",
  "description": "Lusaka Fitness Squad official merchandise and running gear.",
  "url": "{{ $siteUrl ?? 'https://www.lfszambia.run' }}/shop",
  "numberOfItems": {{ $total }},
  "itemListElement": [
    @foreach($products as $i => $product)
    {
      "@@type": "ListItem",
      "position": {{ (($currentPage - 1) * 12) + $i + 1 }},
      "item": {
        "@@type": "Product",
        "name": "{{ addslashes(htmlspecialchars($product['name'])) }}",
        "url": "{{ $siteUrl ?? 'https://www.lfszambia.run' }}/shop/product/{{ $product['slug'] }}",
        "image": "{{ $product['thumbnail'] ?? '' }}",
        "offers": {
          "@@type": "Offer",
          "price": "{{ $product['price'] }}",
          "priceCurrency": "ZMW",
          "availability": "@php
            $inStock = false;
            if (!empty($product['sizes']) && is_array($product['sizes'])) {
              foreach ($product['sizes'] as $s) {
                if (($s['stock'] ?? 0) > 0) { $inStock = true; break; }
              }
            } else {
              $inStock = ($product['totalStock'] ?? 0) > 0;
            }
            echo $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
          @endphp"
        }
      }
    }{{ $i < count($products) - 1 ? ',' : '' }}
    @endforeach
  ]
}
</script>

<!-- Page JS -->

@endsection
