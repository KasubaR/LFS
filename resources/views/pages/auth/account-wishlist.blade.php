@extends('layouts.app')

@section('content')
@php
  $wishlistItems = $wishlistItems ?? [];
@endphp

<x-account-shell
  active-tab="wishlist"
  title="Wishlist"
  description="Gear you have saved for race day — or just because it looks good."
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  @if(empty($wishlistItems))
    <div class="account-empty" role="status">
      <div class="account-empty__icon" aria-hidden="true"><i class="fas fa-heart"></i></div>
      <h2 class="account-empty__title">Your wishlist is empty</h2>
      <p class="account-empty__text">Tap the heart on any shop product to save it here.</p>
      <a href="{{ url('/shop') }}" class="btn btn-primary">Browse the shop</a>
    </div>
  @else
    <div class="account-wishlist-grid">
      @foreach($wishlistItems as $product)
        <article class="account-wishlist-card">
          <a href="{{ url('/shop/product/'.$product['slug']) }}" class="account-wishlist-card__media">
            <img
              src="{{ $product['thumbnail'] ?? '/images/products/placeholder.webp' }}"
              alt="{{ $product['name'] ?? '' }}"
              width="320"
              height="320"
              loading="lazy">
          </a>
          <div class="account-wishlist-card__body">
            <h2 class="account-wishlist-card__name">
              <a href="{{ url('/shop/product/'.$product['slug']) }}">{{ $product['name'] }}</a>
            </h2>
            <p class="account-wishlist-card__price">K{{ number_format((float) ($product['price'] ?? 0), 0) }}</p>
            <div class="account-wishlist-card__actions">
              <a href="{{ url('/shop/product/'.$product['slug']) }}" class="btn btn-primary btn-sm">
                View product
              </a>
              <form action="{{ route('account.wishlist.destroy', $product['_id'] ?? $product['id']) }}" method="post">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline btn-sm">
                  Remove
                </button>
              </form>
            </div>
          </div>
        </article>
      @endforeach
    </div>
  @endif
</x-account-shell>
@endsection
