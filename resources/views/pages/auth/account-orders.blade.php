@extends('layouts.app')

@section('content')
@php
  $orders = $orders ?? [];
  $statusLabels = [
      'pending_payment' => 'Pending payment',
      'paid' => 'Paid',
      'processing' => 'Processing',
      'shipped' => 'Shipped',
      'completed' => 'Completed',
      'cancelled' => 'Cancelled',
      'payment_failed' => 'Payment failed',
  ];
@endphp

<x-account-shell
  active-tab="orders"
  title="Orders"
  description="Track your LFS shop purchases and payment status."
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  @if(empty($orders))
    <div class="account-empty" role="status">
      <div class="account-empty__icon" aria-hidden="true"><i class="fas fa-bag-shopping"></i></div>
      <h2 class="account-empty__title">No orders yet</h2>
      <p class="account-empty__text">When you buy LFS gear, your orders will show up here.</p>
      <a href="{{ url('/shop') }}" class="btn btn-primary">Browse the shop</a>
    </div>
  @else
    <ul class="account-order-list">
      @foreach($orders as $order)
        @php
          $badge = match ($order['status']) {
              'paid', 'completed', 'shipped', 'processing' => 'active',
              'pending_payment' => 'pending',
              'payment_failed', 'cancelled' => 'expired',
              default => 'draft',
          };
        @endphp
        <li class="account-order-card">
          <div class="account-order-card__main">
            <div class="account-order-card__top">
              <a href="{{ route('account.orders.show', $order['orderNumber']) }}" class="account-order-card__number">
                {{ $order['orderNumber'] }}
              </a>
              <span class="auth-status-badge auth-status-badge--{{ $badge }}">
                {{ $statusLabels[$order['status']] ?? str_replace('_', ' ', $order['status']) }}
              </span>
            </div>
            <p class="account-order-card__meta">
              {{ date('j M Y', strtotime($order['createdAt'])) }}
              · {{ (int) ($order['itemCount'] ?? 0) }} {{ (int) ($order['itemCount'] ?? 0) === 1 ? 'item' : 'items' }}
            </p>
          </div>
          <div class="account-order-card__side">
            <div class="account-order-card__total">K{{ number_format((float) $order['total'], 2) }}</div>
            <a href="{{ route('account.orders.show', $order['orderNumber']) }}" class="account-order-card__link">
              View details
            </a>
          </div>
        </li>
      @endforeach
    </ul>
  @endif
</x-account-shell>
@endsection
