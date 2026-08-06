@extends('layouts.app')

@section('content')
@php
  $payments = $payments ?? [];
  $statusLabels = [
      'paid' => 'Paid',
      'partially_paid' => 'Partially paid',
      'pending' => 'Pending',
      'failed' => 'Failed',
      'refunded' => 'Refunded',
  ];
@endphp

<x-account-shell
  active-tab="payments"
  title="Payment History"
  description="Every membership payment you've made, with downloadable receipts."
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  @if(empty($payments))
    <div class="account-empty" role="status">
      <div class="account-empty__icon" aria-hidden="true"><i class="fas fa-receipt"></i></div>
      <h2 class="account-empty__title">No payments yet</h2>
      <p class="account-empty__text">Once you pay for a membership plan, your payment history will show up here.</p>
    </div>
  @else
    <ul class="account-payment-list">
      @foreach($payments as $payment)
        @php
          $badge = match ($payment['status']) {
              'paid' => 'active',
              'partially_paid', 'pending' => 'pending',
              'failed', 'refunded' => 'expired',
              default => 'draft',
          };
          $canDownload = (float) $payment['amountPaid'] > 0;
        @endphp
        <li class="account-payment-card">
          <div class="account-payment-card__main">
            <div class="account-payment-card__top">
              <span class="account-payment-card__ref">
                {{ $payment['receiptNumber'] ?? $payment['paymentReference'] ?? 'Payment #'.$payment['id'] }}
              </span>
              <span class="auth-status-badge auth-status-badge--{{ $badge }}">
                {{ $statusLabels[$payment['status']] ?? str_replace('_', ' ', $payment['status']) }}
              </span>
            </div>
            <p class="account-payment-card__meta">
              {{ $payment['planName'] ?? 'Membership plan' }}
              @if($payment['membershipNumber'])
                · {{ $payment['membershipNumber'] }}
              @endif
              · {{ date('j M Y', strtotime($payment['paidAt'] ?? $payment['createdAt'])) }}
            </p>
          </div>
          <div class="account-payment-card__side">
            <div class="account-payment-card__total">
              K{{ number_format((float) $payment['amountPaid'], 2) }}
            </div>
            @if($canDownload)
              @php
                $receiptFilename = 'LFS-Receipt-'.($payment['receiptNumber'] ?: $payment['paymentReference'] ?: $payment['id']).'.pdf';
              @endphp
              <a
                href="{{ route('account.payments.receipt', $payment['id']) }}"
                class="account-payment-card__link"
                download="{{ $receiptFilename }}"
              >
                <i class="fas fa-download" aria-hidden="true"></i> Download receipt
              </a>
            @endif
          </div>
        </li>
      @endforeach
    </ul>
  @endif
</x-account-shell>
@endsection
