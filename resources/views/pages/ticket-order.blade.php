@extends('layouts.app')

@section('content')
@php
/**
 * Ticket order page: waiting for payment, confirmed, held for review, or not completed.
 * The confirmation email is the ticket; this page shows the same details on screen.
 *
 * Variables: $order, $event, $tickets, $payment (nullable)
 */
$status = $order->status;
$money = fn ($n): string => 'K'.number_format((float) $n, 2);
$dateStr = $event->event_date ? $event->event_date->format('l, j F Y \a\t H:i') : 'Date to be announced';

$isPaid = $status === \App\Models\EventTicketOrder::STATUS_PAID;
$isPending = $status === \App\Models\EventTicketOrder::STATUS_PENDING_PAYMENT;
$isReview = $status === \App\Models\EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED;
// A cancelled order may still be settled by a late payment, so keep checking for a while.
$stillWatching = $isPending || ($status === \App\Models\EventTicketOrder::STATUS_CANCELLED && $payment && $payment->status === 'pending');
@endphp

<div class="checkout-header">
  <div class="checkout-header__inner">
    <h1 class="checkout-header__title">{{ $isPaid ? 'Your tickets' : 'Your order' }}</h1>
  </div>
</div>

<section class="checkout-section">
  <div class="checkout-section__inner" style="max-width:720px; margin:0 auto;">

    <div class="checkout-card"
         id="order-card"
         data-status-url="{{ url('/tickets/'.$order->order_number.'/status') }}"
         data-status="{{ $status }}"
         data-watching="{{ $stillWatching ? '1' : '0' }}"
         data-expires-at="{{ $order->expires_at?->toIso8601String() }}">
      <div class="checkout-card__body">

        @if($isPaid)
          <div class="tk-status tk-status--paid">
            <div class="tk-status__icon" aria-hidden="true"><i class="fas fa-circle-check"></i></div>
            <h2>Payment successful</h2>
            <p>Your tickets are confirmed. We've emailed a confirmation to <strong>{{ $order->buyer_email }}</strong>. Show that email at the entrance.</p>
          </div>
        @elseif($isPending)
          <div class="tk-status tk-status--wait" id="wait-box">
            <div class="tk-status__icon" aria-hidden="true"><i class="fas fa-mobile-screen"></i></div>
            <h2>Approve the payment on your phone</h2>
            <p>A prompt was sent to <strong>{{ $order->payer_phone }}</strong>. Your tickets are held for <span class="tk-countdown" id="countdown">…</span>.</p>
            <p class="tk-hint" id="poll-msg"><span class="live-dot"></span> Waiting for payment confirmation…</p>
            <p class="tk-hint">Already approved it? You don't need to pay again. This page updates by itself.</p>
          </div>
        @elseif($isReview)
          <div class="tk-status tk-status--wait">
            <div class="tk-status__icon" aria-hidden="true"><i class="fas fa-hourglass-half"></i></div>
            <h2>Payment received, awaiting confirmation</h2>
            <p>We received your payment but could not confirm your tickets automatically. <strong>Your tickets are not confirmed yet.</strong> The organisers will review this and contact you. If the tickets are no longer available, you will be refunded.</p>
          </div>
        @elseif($status === \App\Models\EventTicketOrder::STATUS_CANCELLED)
          <div class="tk-status tk-status--bad" id="expired-box">
            <div class="tk-status__icon" aria-hidden="true"><i class="fas fa-clock"></i></div>
            <h2>Your reservation expired</h2>
            <p>If you already approved the payment, you do not need to pay again — we will confirm it or refund it, and email you either way.</p>
            <p><a class="btn btn-primary" href="{{ url('/events/'.$event->slug.'/tickets') }}">Start a new order</a></p>
          </div>
        @else
          <div class="tk-status tk-status--bad">
            <div class="tk-status__icon" aria-hidden="true"><i class="fas fa-circle-xmark"></i></div>
            <h2>Payment didn't go through</h2>
            <p>Nothing was charged for this order. You can try again.</p>
            <p><a class="btn btn-primary" href="{{ url('/events/'.$event->slug.'/tickets') }}">Try again</a></p>
          </div>
        @endif

        <h3 style="margin-top:1.5rem;">{{ $event->title }}</h3>
        <div class="tk-kv"><span>Date and time</span><span>{{ $dateStr }}</span></div>
        <div class="tk-kv"><span>Venue</span><span>{{ $event->location ?: 'To be announced' }}</span></div>
        <div class="tk-kv"><span>Buyer</span><span>{{ $order->buyer_name }}</span></div>
        <div class="tk-kv"><span>Tickets</span><span>{{ $order->quantity }}</span></div>
        <div class="tk-kv"><span>Total {{ $isPaid ? 'paid' : 'payable' }}</span><span><strong>{{ $money($order->total) }}</strong></span></div>
        <div class="tk-kv"><span>Order number</span><span>{{ $order->order_number }}</span></div>
        @if($isPaid && $payment)
          <div class="tk-kv"><span>Payment reference</span><span>{{ $payment->transaction_id }}</span></div>
        @endif

        @if($isPaid)
          <h3 style="margin-top:1.5rem;">Attendees</h3>
          @foreach($tickets as $ticket)
            <div class="tk-ticket">
              <div>
                <div class="tk-ticket__name">{{ $ticket->attendee_name }}</div>
                <div class="tk-ticket__ref">{{ $ticket->ticket_reference }}</div>
                @if($ticket->is_member_discount)
                  <div class="tk-ticket__tag">Member ticket · membership no. {{ $ticket->membership_number }} · bring your card or ID</div>
                @endif
              </div>
              <div>{{ $money($ticket->price) }}</div>
            </div>
          @endforeach
          <p class="tk-hint">Names can't be changed after payment. If something is wrong, contact the organisers before the event.</p>
        @endif

        <p class="tk-hint" style="margin-top:1.5rem;">
          Lost this page? <a href="{{ url('/tickets/find') }}">Find my tickets</a> ·
          <a href="{{ url('/events/'.$event->slug) }}">Back to the event</a>
        </p>
      </div>
    </div>

  </div>
</section>
@endsection
