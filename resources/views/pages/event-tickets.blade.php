@extends('layouts.app')

@section('content')
@php
/**
 * Ticket checkout for paid events.
 *
 * Variables: $event (App\Models\Event), $availability (AvailabilityResult),
 * $maxSelectable, $memberPrice, $standardPrice, $saving, $user (nullable),
 * $preview (PricingResult for 1 ticket), $csrfToken.
 *
 * Every price shown here is display only; the server re-prices the order.
 */
$unavailable = ! $availability->ok();
$dateStr = $event->event_date ? $event->event_date->format('l, j F Y \a\t H:i') : 'Date to be announced';
$loginUrl = Route::has('login') ? route('login') : url('/login');
$fmt = fn (float $n): string => 'K'.number_format($n, $n == floor($n) ? 0 : 2);

$prefName  = $user?->name ?? '';
$prefEmail = $user?->email ?? '';
$prefPhone = $user?->phone ?? '';
@endphp

<div class="checkout-header">
  <div class="checkout-header__inner">
    <h1 class="checkout-header__title">Tickets</h1>
  </div>
</div>

<div class="flag-stripe flag-stripe--on-black" aria-hidden="true">
  <span></span><span></span><span></span><span></span>
</div>

<section class="checkout-section" aria-label="Buy tickets">
  <div class="checkout-section__inner">
    <div class="checkout-layout">

      <div class="checkout-main">

        <div class="checkout-card">
          <div class="checkout-card__header">
            <div class="checkout-card__icon" aria-hidden="true"><i class="fas fa-ticket"></i></div>
            <h2 class="checkout-card__title">{{ $event->title }}</h2>
          </div>
          <div class="checkout-card__body">
            <p class="tk-meta"><i class="fas fa-calendar-day" aria-hidden="true"></i> {{ $dateStr }}</p>
            @if($event->location)
              <p class="tk-meta"><i class="fas fa-location-dot" aria-hidden="true"></i> {{ $event->location }}</p>
            @endif
            <p class="tk-meta">
              <i class="fas fa-tag" aria-hidden="true"></i>
              Members {{ $fmt($memberPrice) }} (one ticket per member) · Everyone else {{ $fmt($standardPrice) }}
            </p>
          </div>
        </div>

        @if($unavailable)
          <div class="checkout-card">
            <div class="checkout-card__body">
              <div class="checkout-error-msg" role="alert">
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                <span>{{ $availability->message() }}</span>
              </div>
              <div class="checkout-nav-btns" style="margin-top:1rem;">
                <a class="btn btn-back" href="{{ url('/events/'.$event->slug) }}"><i class="fas fa-arrow-left"></i> Back to event</a>
              </div>
            </div>
          </div>
        @else

        <form id="ticket-form" novalidate autocomplete="on"
              data-quote-url="{{ url('/events/'.$event->slug.'/tickets/quote') }}"
              data-order-url="{{ url('/events/'.$event->slug.'/tickets/order') }}"
              data-csrf="{{ $csrfToken }}"
              data-max="{{ $maxSelectable }}"
              data-saving="{{ $saving }}"
              data-register-url="{{ route('register') }}"
              data-account-url="{{ url('/account') }}"
              data-logged-in="{{ $user ? '1' : '0' }}">

          {{-- ── Quantity + attendees ── --}}
          <div class="checkout-card">
            <div class="checkout-card__header">
              <div class="checkout-card__icon" aria-hidden="true"><i class="fas fa-users"></i></div>
              <h2 class="checkout-card__title">Your tickets</h2>
            </div>
            <div class="checkout-card__body">

              <div class="checkout-form__group">
                <label class="checkout-form__label" for="quantity">How many tickets?</label>
                <select class="checkout-form__input" id="quantity" name="quantity">
                  @for($i = 1; $i <= $maxSelectable; $i++)
                    <option value="{{ $i }}">{{ $i }}</option>
                  @endfor
                </select>
                @if($availability->remaining !== null)
                  <small class="tk-hint">{{ $availability->remaining }} left</small>
                @endif
              </div>

              <div id="member-box" class="tk-member-box">
                <div id="member-status" class="tk-member-status" aria-live="polite"></div>

                @guest
                <details id="member-lookup" class="tk-details">
                  <summary>Have an LFS membership number? Get the member price</summary>
                  <div class="checkout-form__row">
                    <div class="checkout-form__group">
                      <label class="checkout-form__label" for="membershipNumber">Membership number</label>
                      <input class="checkout-form__input" type="text" id="membershipNumber" name="membershipNumber" placeholder="LFS-000412" autocomplete="off">
                    </div>
                    <div class="checkout-form__group">
                      <label class="checkout-form__label" for="membershipEmail">Email registered on your membership</label>
                      <input class="checkout-form__input" type="email" id="membershipEmail" name="membershipEmail" placeholder="you@example.com" autocomplete="off">
                    </div>
                  </div>
                  <small class="tk-hint">Use the email address registered on your LFS membership. If you no longer have access to it, <a href="{{ $loginUrl }}">log in</a> instead.</small>
                  <div><button type="button" class="btn btn-back" id="member-apply">Check membership</button></div>
                </details>
                @endguest
              </div>

              <label class="tk-check" id="attending-wrap">
                <input type="checkbox" id="buyerAttending" name="buyerAttending" checked>
                <span>One of these tickets is for me</span>
              </label>

              <div id="attendees" class="tk-attendees" aria-live="polite"></div>
              <p class="tk-warn"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                Names can't be changed after payment. Check each name before you pay.</p>
            </div>
          </div>

          {{-- ── Buyer ── --}}
          <div class="checkout-card">
            <div class="checkout-card__header">
              <div class="checkout-card__icon" aria-hidden="true"><i class="fas fa-user"></i></div>
              <h2 class="checkout-card__title">Your details</h2>
            </div>
            <div class="checkout-card__body">
              <p class="tk-hint" style="margin-top:0;">The buyer's details. Your confirmation email is sent here.</p>
              <div class="checkout-form__group">
                <label class="checkout-form__label" for="buyerName">Full name <span class="required-star" aria-hidden="true">*</span></label>
                <input class="checkout-form__input" type="text" id="buyerName" name="buyerName" value="{{ $prefName }}" autocomplete="name" required>
              </div>
              <div class="checkout-form__row">
                <div class="checkout-form__group">
                  <label class="checkout-form__label" for="buyerEmail">Email <span class="required-star" aria-hidden="true">*</span></label>
                  <input class="checkout-form__input" type="email" id="buyerEmail" name="buyerEmail" value="{{ $prefEmail }}" autocomplete="email" required>
                </div>
                <div class="checkout-form__group">
                  <label class="checkout-form__label" for="buyerPhone">Phone</label>
                  <input class="checkout-form__input" type="tel" id="buyerPhone" name="buyerPhone" value="{{ $prefPhone }}" autocomplete="tel" inputmode="tel">
                </div>
              </div>
            </div>
          </div>

          {{-- ── Payment ── --}}
          <div class="checkout-card">
            <div class="checkout-card__header">
              <div class="checkout-card__icon" aria-hidden="true"><i class="fas fa-mobile-screen"></i></div>
              <h2 class="checkout-card__title">Pay with mobile money</h2>
            </div>
            <div class="checkout-card__body">
              <div class="payment-methods-group">
                <div class="payment-option">
                  <input type="radio" class="payment-option__input" id="pm-mtn" name="provider" value="mtn" checked>
                  <label class="payment-option__label" for="pm-mtn">
                    <div class="payment-option__radio" aria-hidden="true"></div>
                    <div class="payment-option__icon payment-option__icon--mtn" aria-hidden="true">MTN</div>
                    <div class="payment-option__info"><div class="payment-option__name">MTN Mobile Money</div></div>
                  </label>
                </div>
                <div class="payment-option">
                  <input type="radio" class="payment-option__input" id="pm-airtel" name="provider" value="airtel">
                  <label class="payment-option__label" for="pm-airtel">
                    <div class="payment-option__radio" aria-hidden="true"></div>
                    <div class="payment-option__icon payment-option__icon--airtel" aria-hidden="true">AIRTEL</div>
                    <div class="payment-option__info"><div class="payment-option__name">Airtel Money</div></div>
                  </label>
                </div>
              </div>
              <div class="checkout-form__group">
                <label class="checkout-form__label" for="payerPhone">Mobile money number to charge <span class="required-star" aria-hidden="true">*</span></label>
                <input class="checkout-form__input" type="tel" id="payerPhone" name="payerPhone" value="{{ $prefPhone }}" placeholder="+260 97X XXX XXX" inputmode="tel" required>
                <small class="tk-hint">A prompt is sent to this number. It can be different from your contact phone.</small>
              </div>

              <div id="ticket-error" class="checkout-error-msg" role="alert" aria-live="assertive" style="display:none;">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                <span id="ticket-error-text"></span>
              </div>
              <div id="price-changed" class="tk-price-changed" style="display:none;" role="alert">
                <strong>The total has changed.</strong> Please review the new total on the right, then pay again.
              </div>
            </div>
          </div>

          <div class="checkout-nav-btns">
            <a href="{{ url('/events/'.$event->slug) }}" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back</a>
            <button type="submit" class="btn btn-place-order" id="pay-btn"><i class="fas fa-lock"></i> <span id="pay-label">Pay</span></button>
          </div>
        </form>
        @endif
      </div>

      {{-- ── Order summary (always visible) ── --}}
      <aside class="checkout-sidebar" aria-label="Order summary">
        <div class="checkout-summary" id="order-summary">
          <h3 class="checkout-summary__title">Order summary</h3>
          <div class="tk-summary-event">{{ $event->title }}</div>
          <div id="summary-lines" class="tk-summary-lines" aria-live="polite">
            <div class="checkout-summary__row"><span>Ticket × 1</span><span>{{ $fmt($standardPrice) }}</span></div>
          </div>
          <div class="checkout-summary__divider" aria-hidden="true"></div>
          <div class="checkout-summary__row checkout-summary__total-row">
            <span class="checkout-summary__total-label">Total payable</span>
            <span class="checkout-summary__total-amount" id="summary-total">{{ $fmt($standardPrice) }}</span>
          </div>
          <div id="summary-join" class="tk-join" style="display:none;"></div>
          <p class="checkout-summary__note">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            Payment through Lenco mobile money. Prices are in Zambian Kwacha (ZMW).
          </p>
          <p class="checkout-summary__note" id="summary-hold">
            <i class="fas fa-clock" aria-hidden="true"></i>
            Your tickets are held for {{ (int) config('tickets.reservation_minutes', 15) }} minutes once you place the order.
          </p>
          <p class="checkout-summary__note">
            Lost your confirmation? <a href="{{ url('/tickets/find') }}">Find my tickets</a>.
          </p>
        </div>
      </aside>

    </div>
  </div>
</section>

<div class="checkout-loading" aria-live="assertive" aria-label="Processing" role="status">
  <div class="checkout-loading__spinner" aria-hidden="true"></div>
  <div class="checkout-loading__msg">Processing…</div>
</div>
@endsection
