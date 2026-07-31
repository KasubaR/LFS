@extends('layouts.app')

@section('content')
<!-- ══════════════════════════════════════════
     PAGE HEADER
     ══════════════════════════════════════════ -->
<section class="cart-header">
  <div class="cart-header__inner">

    <nav class="shop-breadcrumb" aria-label="Breadcrumb">
      <ol itemscope itemtype="https://schema.org/BreadcrumbList">
        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
          <a itemprop="item" href="{{ url('/') }}"><span itemprop="name">Home</span></a>
          <meta itemprop="position" content="1">
          <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </li>
        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
          <a itemprop="item" href="{{ url('/shop') }}"><span itemprop="name">Shop</span></a>
          <meta itemprop="position" content="2">
          <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </li>
        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
          <span itemprop="name" aria-current="page">Cart</span>
          <meta itemprop="position" content="3">
        </li>
      </ol>
    </nav>

    <h1 class="cart-header__title">
      Your Cart
      @if($itemCount > 0)
      <span class="cart-header__count">
        {{ $itemCount }} item{{ $itemCount !== 1 ? 's' : '' }}
      </span>
      @endif
    </h1>

    <div class="flag-stripe flag-stripe--on-black mt-4" aria-hidden="true">
      <span></span><span></span><span></span><span></span>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     CART BODY
     ══════════════════════════════════════════ -->
<section class="cart-section">
  <div class="cart-section__inner">

    @if(empty($cart))

    <!-- ── EMPTY STATE ── -->
    <div class="cart-empty">
      <div class="cart-empty__icon" aria-hidden="true">
        <i class="fas fa-shopping-bag"></i>
      </div>
      <h2 class="cart-empty__title">Your cart is empty</h2>
      <p class="cart-empty__desc">
        Browse our official LFS running gear and regalia.<br>
        Look the part. Run the part.
      </p>
      <a href="{{ url('/shop') }}" class="btn btn-primary cart-empty__btn">
        <i class="fas fa-store" aria-hidden="true"></i>
        Browse Shop
      </a>
    </div>

    @else

    <div class="cart-layout">

      <!-- ── ITEMS LIST ── -->
      <div class="cart-items" role="list" aria-label="Cart items">

        @foreach($cart as $item)
          @php
          $itemName  = $item['name'];
          $itemSlug  = $item['slug'];
          $itemKey   = $item['key'];
          $itemImage = $item['image'] ?: '/images/products/placeholder.webp';
          $itemSize  = $item['size'];
          $unitPrice = 'K ' . number_format((float)$item['price'], 0);
          $lineTotal = 'K ' . number_format((float)$item['price'] * (int)$item['qty'], 0);
          $itemUrl   = url('/shop/product/'.$itemSlug);
          @endphp
        <article class="cart-item" role="listitem"
          data-key="{{ $itemKey }}"
          data-price="{{ (float)$item['price'] }}">

          <!-- Thumbnail -->
          <a href="{{ $itemUrl }}" class="cart-item__img-wrap" aria-label="{{ $itemName }}">
            <img
              src="{{ $itemImage }}"
              alt="{{ $itemName }}"
              class="cart-item__img"
              loading="lazy"
              width="100"
              height="100">
          </a>

          <!-- Details -->
          <div class="cart-item__details">
            <a href="{{ $itemUrl }}" class="cart-item__name">{{ $itemName }}</a>
            <span class="cart-item__meta">Size: <strong>{{ $itemSize }}</strong></span>
            <span class="cart-item__unit-price">{{ $unitPrice }} each</span>
          </div>

          <!-- Qty stepper -->
          <div class="cart-item__qty" role="group" aria-label="Quantity for {{ $itemName }}">
            <button class="cart-item__qty-btn" data-step="-1" aria-label="Decrease quantity">
              <i class="fas fa-minus" aria-hidden="true"></i>
            </button>
            <input
              type="number"
              class="cart-item__qty-input"
              data-key="{{ $itemKey }}"
              value="{{ (int)$item['qty'] }}"
              min="0"
              max="99"
              aria-label="Quantity"
            >
            <button class="cart-item__qty-btn" data-step="1" aria-label="Increase quantity">
              <i class="fas fa-plus" aria-hidden="true"></i>
            </button>
          </div>

          <!-- Line total -->
          <div class="cart-item__line-total" aria-label="Item total">
            {{ $lineTotal }}
          </div>

          <!-- Remove -->
          <button
            class="cart-item__remove"
            data-remove-key="{{ $itemKey }}"
            aria-label="Remove {{ $itemName }} from cart"
            title="Remove item">
            <i class="fas fa-times" aria-hidden="true"></i>
          </button>

        </article>
        @endforeach

      </div><!-- /.cart-items -->

      <!-- ── ORDER SUMMARY ── -->
      <aside class="cart-summary" aria-label="Order summary">
        <h2 class="cart-summary__title">Order Summary</h2>

        <div class="cart-summary__row">
          <span>Subtotal ({{ $itemCount }} item{{ $itemCount !== 1 ? 's' : '' }})</span>
          <strong class="cart-summary__subtotal">{{ $subtotal }}</strong>
        </div>

        <div class="cart-summary__divider" aria-hidden="true"></div>

        <div class="cart-summary__row cart-summary__total-row">
          <span class="cart-summary__total-label">Total</span>
          <strong class="cart-summary__total-amount cart-summary__subtotal">{{ $subtotal }}</strong>
        </div>

        <p class="cart-summary__note">
          <i class="fas fa-info-circle" aria-hidden="true"></i>
          Delivery and payment details confirmed on order.
        </p>

        <a href="/shop/checkout"
           class="btn btn-primary cart-summary__cta">
          Proceed to Checkout
          <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </a>

        <a href="{{ url('/shop') }}" class="btn cart-summary__continue">
          <i class="fas fa-arrow-left" aria-hidden="true"></i>
          Continue Shopping
        </a>
      </aside>

    </div><!-- /.cart-layout -->

    @endif

  </div><!-- /.cart-section__inner -->
</section>

@endsection
