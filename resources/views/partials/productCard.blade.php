@php
if (!isset($formatPrice) || !is_callable($formatPrice)) {
    $formatPrice = function (int|float $amount): string {
        return 'K ' . number_format($amount, 0, '.', ',');
    };
}

$inStock      = false;
if (!empty($product['sizes'])) {
    foreach ($product['sizes'] as $s) {
        if (($s['stock'] ?? 0) > 0) { $inStock = true; break; }
    }
} else {
    $inStock = ($product['totalStock'] ?? 0) > 0;
}

$onSale      = !empty($product['comparePrice']) && $product['comparePrice'] > $product['price'];
$firstImage  = $product['thumbnail'] ?? $product['images'][0] ?? '/images/products/placeholder.webp';
$productUrl  = url('/shop/product/'.$product['slug']);

$sizesInStock = [];
foreach ($product['sizes'] ?? [] as $s) {
    if (($s['stock'] ?? 0) > 0) {
        $sizesInStock[] = $s['size'];
    }
}

$categoryLabel = str_replace('-', ' ', $product['category'] ?? '');
@endphp

<article
  class="product-card"
  data-product-id="{{ $product['_id'] ?? '' }}"
  data-product-slug="{{ $product['slug'] ?? '' }}"
  data-category="{{ $product['category'] ?? '' }}"
  data-gender="{{ $product['gender'] ?? '' }}"
  data-total-stock="{{ (int)($product['totalStock'] ?? 0) }}"
  itemscope
  itemtype="https://schema.org/Product"
  aria-label="{{ $product['name'] ?? '' }}"
>

<!-- ── Image wrapper ── -->
<div class="product-card__img-wrap" tabindex="-1" aria-hidden="true">
    <img
      src="{{ $firstImage }}"
      alt="{{ $product['name'] ?? '' }}"
      class="product-card__img"
      loading="lazy"
      width="400"
      height="400"
      itemprop="image"
    >

    @if($onSale)
      <span class="product-card__badge product-card__badge--sale">SALE</span>
    @endif

    @if(!$inStock)
      <span class="product-card__badge product-card__badge--sold-out">Sold Out</span>
    @endif

    @if(!empty($product['featured']))
      <span class="product-card__badge product-card__badge--featured">
        <i class="fas fa-star" aria-hidden="true"></i> Featured
      </span>
    @endif

    <button
      class="product-card__quick-view"
      data-product-id="{{ $product['_id'] ?? '' }}"
      data-slug="{{ $product['slug'] ?? '' }}"
      aria-label="Quick view {{ $product['name'] ?? '' }}"
      type="button"
    >
      <i class="fas fa-eye" aria-hidden="true"></i>
      Quick View
    </button>
  </div>

  <div class="product-card__body">

    <span class="product-card__category text-label" aria-label="Category: {{ $product['category'] ?? '' }}">
      {{ $categoryLabel }}
    </span>

    <h3 class="product-card__name" itemprop="name">
      <a href="{{ $productUrl }}">{{ $product['name'] ?? '' }}</a>
    </h3>

    @if(!empty($sizesInStock))
    <div class="product-card__sizes" aria-label="Available sizes">
      @foreach(array_slice($sizesInStock, 0, 5) as $size)
        <span class="product-card__size-chip">{{ $size }}</span>
      @endforeach
      @if(count($sizesInStock) > 5)
        <span class="product-card__size-chip product-card__size-chip--more">+{{ count($sizesInStock) - 5 }}</span>
      @endif
    </div>
    @endif

    <div class="product-card__price-row" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
      <meta itemprop="priceCurrency" content="ZMW">
      <meta itemprop="price" content="{{ $product['price'] ?? '' }}">
      <meta itemprop="availability" content="{{ $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' }}">
      <meta itemprop="url" content="{{ $productUrl }}">

      <span class="product-card__price product-card__price--current">
        {{ $formatPrice($product['price'] ?? 0) }}
      </span>

      @if($onSale)
        <span class="product-card__price product-card__price--compare" aria-label="Was {{ $formatPrice($product['comparePrice']) }}">
          {{ $formatPrice($product['comparePrice']) }}
        </span>
      @endif

      @if(!$inStock)
        <span class="product-card__stock product-card__stock--out">Out of Stock</span>
      @elseif(($product['totalStock'] ?? 0) <= 5 && ($product['totalStock'] ?? 0) > 0)
        <span class="product-card__stock product-card__stock--low">Only {{ (int) $product['totalStock'] }} left</span>
      @endif
    </div>

    <div class="product-card__actions">

      @if($inStock)
        <button
          class="btn btn-primary product-card__btn-cart product-card__btn-cart--icon js-quick-add"
          data-product-id="{{ $product['_id'] ?? '' }}"
          data-slug="{{ $product['slug'] ?? '' }}"
          data-sizes="{{ e(json_encode($sizesInStock)) }}"
          type="button"
          aria-label="Add {{ $product['name'] ?? '' }} to cart"
        >
          <i class="fas fa-shopping-bag" aria-hidden="true"></i>
        </button>
      @else
        <button class="btn btn-outline product-card__btn-cart" disabled type="button">
          <i class="fas fa-ban" aria-hidden="true"></i>
          Out of Stock
        </button>
      @endif

      @auth
        @php
          $productId = (string) ($product['_id'] ?? $product['id'] ?? '');
          $inWishlist = in_array($productId, $wishlistProductIds ?? [], true);
        @endphp
        @if($inWishlist)
          <form action="{{ route('account.wishlist.destroy', $productId) }}" method="post" class="product-card__wishlist-form">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline product-card__btn-wishlist is-active" aria-label="Remove {{ $product['name'] ?? '' }} from wishlist">
              <i class="fas fa-heart" aria-hidden="true"></i>
            </button>
          </form>
        @else
          <form action="{{ route('account.wishlist.store') }}" method="post" class="product-card__wishlist-form">
            @csrf
            <input type="hidden" name="product_id" value="{{ $productId }}">
            <button type="submit" class="btn btn-outline product-card__btn-wishlist" aria-label="Add {{ $product['name'] ?? '' }} to wishlist">
              <i class="far fa-heart" aria-hidden="true"></i>
            </button>
          </form>
        @endif
      @endauth

      <a
        href="{{ $productUrl }}"
        class="btn btn-outline product-card__btn-detail"
        aria-label="View details for {{ $product['name'] ?? '' }}"
      >
        View Details
      </a>

    </div>
  </div>

</article>
