@extends('layouts.app')

@section('content')
@php $sent = $sent ?? false; @endphp

<div class="checkout-header">
  <div class="checkout-header__inner">
    <h1 class="checkout-header__title">Find my tickets</h1>
  </div>
</div>

<section class="checkout-section">
  <div class="checkout-section__inner" style="max-width:560px; margin:0 auto;">
    <div class="checkout-card">
      <div class="checkout-card__body">

        @if($sent)
          {{-- Identical whether or not the details matched. --}}
          <div class="tk-status tk-status--paid">
            <div class="tk-status__icon" aria-hidden="true"><i class="fas fa-envelope-circle-check"></i></div>
            <h2>Check your email</h2>
            <p>If those details match an order, we've emailed the confirmation to the address on the order. It can take a few minutes to arrive.</p>
          </div>
        @else
          <p>Enter your order number and the email you bought with. We'll email your tickets again to that address.</p>
          <form method="POST" action="{{ url('/tickets/find') }}">
            @csrf
            <div class="checkout-form__group">
              <label class="checkout-form__label" for="orderNumber">Order number</label>
              <input class="checkout-form__input" type="text" id="orderNumber" name="orderNumber" placeholder="LFSEV-20261201-AB12C" required>
            </div>
            <div class="checkout-form__group">
              <label class="checkout-form__label" for="email">Email used to buy</label>
              <input class="checkout-form__input" type="email" id="email" name="email" required>
            </div>
            <button type="submit" class="btn btn-primary">Email my tickets</button>
          </form>
        @endif

      </div>
    </div>
  </div>
</section>
@endsection
