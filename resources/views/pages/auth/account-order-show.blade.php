@extends('layouts.app')

@section('content')
@php
  $statusLabels = [
      'pending_payment' => 'Pending payment',
      'paid' => 'Paid',
      'processing' => 'Processing',
      'shipped' => 'Shipped',
      'completed' => 'Completed',
      'cancelled' => 'Cancelled',
      'payment_failed' => 'Payment failed',
  ];
  $badge = match ($order['status']) {
      'paid', 'completed', 'shipped', 'processing' => 'active',
      'pending_payment' => 'pending',
      'payment_failed', 'cancelled' => 'expired',
      default => 'draft',
  };
@endphp

<x-account-shell
  active-tab="orders"
  :title="'Order '.$order['orderNumber']"
  description="Order details and item breakdown."
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  <p class="account-backlink">
    <a href="{{ route('account.orders') }}">
      <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to orders
    </a>
  </p>

  <div class="dashboard-card account-order-detail">
    <div class="account-order-detail__header">
      <div>
        <h2 class="dashboard-card__title">{{ $order['orderNumber'] }}</h2>
        <p class="account-order-card__meta">
          Placed {{ date('j M Y, g:i A', strtotime($order['createdAt'])) }}
        </p>
      </div>
      <span class="auth-status-badge auth-status-badge--{{ $badge }}">
        {{ $statusLabels[$order['status']] ?? str_replace('_', ' ', $order['status']) }}
      </span>
    </div>

    <dl class="account-meta-list">
      <div class="account-meta-list__row">
        <dt>Total</dt>
        <dd>K{{ number_format((float) $order['total'], 2) }}</dd>
      </div>
      <div class="account-meta-list__row">
        <dt>Customer</dt>
        <dd>{{ $order['customerName'] }}</dd>
      </div>
      <div class="account-meta-list__row">
        <dt>Phone</dt>
        <dd>{{ $order['customerPhone'] !== '' ? $order['customerPhone'] : '—' }}</dd>
      </div>
      @if(!empty($order['notes']))
        <div class="account-meta-list__row">
          <dt>Notes</dt>
          <dd>{{ $order['notes'] }}</dd>
        </div>
      @endif
    </dl>

    <h3 class="account-order-detail__items-title">Items</h3>
    <ul class="account-order-items">
      @foreach($order['items'] as $item)
        <li class="account-order-items__row">
          <div>
            <div class="account-order-items__name">{{ $item['name'] }}</div>
            <div class="account-order-items__meta">
              @if($item['size'] !== '')
                Size {{ $item['size'] }} ·
              @endif
              Qty {{ $item['qty'] }}
            </div>
          </div>
          <div class="account-order-items__price">K{{ number_format((float) $item['lineTotal'], 2) }}</div>
        </li>
      @endforeach
    </ul>
  </div>
</x-account-shell>
@endsection
