@extends('layouts.app')

@section('content')
@php
  $planName = $membership->plan?->name ?? 'Membership';
@endphp

<x-account-shell
  active-tab="dashboard"
  title="Outstanding Balance"
  :user="$user"
  :membership="$membership"
  :status="$status ?? null"
>
  <div class="dashboard-card dashboard-card--action">
    <h2 class="dashboard-card__title">Outstanding balance</h2>

    <div class="auth-account-card auth-account-card--payment">
      <p class="auth-account-card__payment-text">
        Your {{ $planName }} membership is active, but the amount recorded for it was
        K{{ number_format($payment['amountPaid'], 2) }} of the K{{ number_format($payment['amount'], 2) }} plan
        price. Please pay the remaining K{{ number_format($balanceOwed, 2) }} with Mobile Money to clear the balance.
      </p>

      <form id="account-payment-form" data-amount="{{ $balanceOwed }}">
        @csrf
        <div class="auth-payment-provider-grid" role="radiogroup" aria-label="Mobile money provider">
          <label class="auth-payment-provider">
            <input type="radio" name="provider" value="mtn" required>
            <span class="auth-payment-provider__card">MTN Mobile Money</span>
          </label>
          <label class="auth-payment-provider">
            <input type="radio" name="provider" value="airtel" required>
            <span class="auth-payment-provider__card">Airtel Money</span>
          </label>
        </div>

        <div class="auth-payment-phone-wrap form-group" data-phone-wrap>
          <label for="account-payment-phone">Mobile money number</label>
          <input type="tel" id="account-payment-phone" name="phone" placeholder="+260 97X XXX XXX" autocomplete="tel">
        </div>

        <button type="submit" class="btn btn-primary w-full justify-center" data-pay-button>
          <i class="fas fa-credit-card mr-2" aria-hidden="true"></i> Pay K{{ number_format($balanceOwed, 2) }}
        </button>
      </form>

      <div id="account-payment-result" class="auth-payment-result" aria-live="polite">
        <div class="auth-payment-result__title" id="account-payment-title">Payment Initiated</div>
        <div class="auth-payment-result__text" id="account-payment-text"></div>
        <div class="auth-payment-result__status" id="account-payment-status">Waiting for payment confirmation…</div>
      </div>

      <div id="account-payment-error" class="auth-payment-error" role="alert" aria-live="assertive">
        <i class="fas fa-exclamation-circle mr-1" aria-hidden="true"></i>
        <span id="account-payment-error-text"></span>
      </div>
    </div>
  </div>
</x-account-shell>
@endsection
