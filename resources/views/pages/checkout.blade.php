@extends('layouts.app')

@section('content')
@php
/**
 * LFS — Lusaka Fitness Squad | checkout.php
 * Multi-step checkout page view.
 * File: src/views/pages/checkout.php
 *
 * Expected PHP variables (set by controller):
 *   $cartItems  — array of cart item objects/arrays:
 *                 { key, productId, name, size, qty, price, subtotal, image }
 *   $cartCount  — int, total item count
 *   $subtotal   — formatted string e.g. "K 1,200"
 *   $total      — formatted string (same as subtotal; shipping free)
 *   $csrfToken  — string CSRF token
 */

$cartItems  = $cartItems  ?? [];
$cartCount  = $cartCount  ?? 0;
$subtotal   = $subtotal   ?? 'K 0';
$total      = $total      ?? 'K 0';
$csrfToken  = $csrfToken  ?? '';

// Page-specific assets (picked up by main layout)
@endphp

<!-- ══════════════════════════════════════════════════════════
     CHECKOUT HEADER
     ══════════════════════════════════════════════════════════ -->

<div class="checkout-header">
  <div class="checkout-header__inner">
    <h1 class="checkout-header__title">Checkout</h1>

    <!-- Step progress indicator: 1 Your Info → 2 Payment → 3 Confirmed -->
    <div class="checkout-steps" role="list" aria-label="Checkout steps">

      <div class="checkout-step checkout-step--active" data-step="1" role="listitem">
        <div class="checkout-step__num" aria-hidden="true">1</div>
        <span class="checkout-step__label">Your Info</span>
      </div>
      <div class="checkout-step__connector" aria-hidden="true"></div>

      <div class="checkout-step" data-step="2" role="listitem">
        <div class="checkout-step__num" aria-hidden="true">2</div>
        <span class="checkout-step__label">Payment</span>
      </div>
      <div class="checkout-step__connector" aria-hidden="true"></div>

      <div class="checkout-step" data-step="3" role="listitem">
        <div class="checkout-step__num" aria-hidden="true">
          <i class="fas fa-check" aria-hidden="true"></i>
        </div>
        <span class="checkout-step__label">Confirmed</span>
      </div>

    </div><!-- /.checkout-steps -->

  </div>
</div><!-- /.checkout-header -->

<!-- Zambian flag stripe -->
<div class="flag-stripe flag-stripe--on-black" aria-hidden="true">
  <span></span><span></span><span></span><span></span>
</div>


<!-- ══════════════════════════════════════════════════════════
     CHECKOUT BODY
     ══════════════════════════════════════════════════════════ -->

<section class="checkout-section" aria-label="Checkout">
  <div class="checkout-section__inner">
    <div class="checkout-layout">

      <!-- ════════════════════════════════════════════════════
           MAIN COLUMN (step panels)
           ════════════════════════════════════════════════════ -->
      <div class="checkout-main">

        <!-- ────────────────────────────────────────────────
             STEP 1 — CUSTOMER INFORMATION
             ──────────────────────────────────────────────── -->
        <div class="checkout-panel checkout-panel--active" data-step-panel="1" aria-labelledby="step1-title">

          <div class="checkout-card">
            <div class="checkout-card__header">
              <div class="checkout-card__icon" aria-hidden="true">
                <i class="fas fa-user"></i>
              </div>
              <h2 class="checkout-card__title" id="step1-title">Your Information</h2>
            </div>

            <div class="checkout-card__body">
              <form
                class="checkout-form"
                data-checkout-form="customer"
                novalidate
                autocomplete="on"
              >
                <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

                <!-- Full Name -->
                <div class="checkout-form__group">
                  <label class="checkout-form__label" for="fullName">
                    Full Name <span class="required-star" aria-hidden="true">*</span>
                  </label>
                  <input
                    class="checkout-form__input"
                    type="text"
                    id="fullName"
                    name="fullName"
                    placeholder="e.g. Chanda Mwale"
                    autocomplete="name"
                    required
                  >
                  <span class="checkout-form__error-msg" role="alert"></span>
                </div>

                <div class="checkout-form__row">

                  <!-- Phone -->
                  <div class="checkout-form__group">
                    <label class="checkout-form__label" for="phone">
                      Phone Number <span class="required-star" aria-hidden="true">*</span>
                    </label>
                    <div class="checkout-form__phone-wrap">
                      <span class="checkout-form__phone-prefix" aria-label="Zambia country code">+260</span>
                      <input
                        class="checkout-form__input"
                        type="tel"
                        id="phone"
                        name="phone"
                        placeholder="97 123 4567"
                        autocomplete="tel-national"
                        inputmode="numeric"
                        required
                      >
                    </div>
                    <span class="checkout-form__error-msg" role="alert"></span>
                  </div>

                  <!-- Email -->
                  <div class="checkout-form__group">
                    <label class="checkout-form__label" for="email">
                      Email Address <span style="font-weight:400; text-transform:none; letter-spacing:0;">(optional)</span>
                    </label>
                    <input
                      class="checkout-form__input"
                      type="email"
                      id="email"
                      name="email"
                      placeholder="you@example.com"
                      autocomplete="email"
                    >
                    <span class="checkout-form__error-msg" role="alert"></span>
                  </div>

                </div><!-- /.checkout-form__row -->

                <!-- Notes -->
                <div class="checkout-form__group">
                  <label class="checkout-form__label" for="notes">
                    Pickup Notes <span style="font-weight:400; text-transform:none; letter-spacing:0;">(optional)</span>
                  </label>
                  <textarea
                    class="checkout-form__textarea"
                    id="notes"
                    name="notes"
                    placeholder="Any instructions for your pickup, preferred time, etc."
                  ></textarea>
                </div>

              </form>
            </div><!-- /.checkout-card__body -->
          </div><!-- /.checkout-card -->

          <!-- Navigation -->
          <div class="checkout-nav-btns">
            <a href="{{ url('/shop/cart') }}" class="btn btn-back">
              <i class="fas fa-arrow-left"></i> Back to Cart
            </a>
            <button class="btn btn-next" data-checkout-next type="button">
              Continue <i class="fas fa-arrow-right"></i>
            </button>
          </div>

        </div><!-- /.checkout-panel[step 1] -->


        <!-- ────────────────────────────────────────────────
             STEP 2 — PICKUP + PAYMENT
             ──────────────────────────────────────────────── -->
<div class="checkout-panel" data-step-panel="2" aria-labelledby="step2-title">

  <!-- PICKUP INFO (unchanged from original) -->
  <div class="checkout-card">
    <div class="checkout-card__header">
      <div class="checkout-card__icon" aria-hidden="true">
        <i class="fas fa-map-marker-alt"></i>
      </div>
      <h2 class="checkout-card__title" id="step2-title">Pickup Information</h2>
    </div>
    <div class="checkout-card__body">
      <div class="checkout-pickup">
        <div class="checkout-pickup__location">
          <div class="checkout-pickup__map-icon" aria-hidden="true">
            <i class="fas fa-map-marker-alt"></i>
          </div>
          <div>
            <div class="checkout-pickup__location-name">LFS Pickup Point</div>
            <div class="checkout-pickup__address">
              CV-6 COMESA Village, Great East Road<br>Lusaka, Zambia
            </div>
          </div>
        </div>
        <div class="checkout-pickup__instructions">
          <div class="checkout-pickup__instructions-title">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            Pickup Instructions
          </div>
          <ul class="checkout-pickup__instructions-list">
            <li class="checkout-pickup__instruction-item">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <span>Bring your <strong>order number</strong> and a valid ID or the phone number used at checkout.</span>
            </li>
            <li class="checkout-pickup__instruction-item">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <span>You will receive a WhatsApp or SMS confirmation once your order is ready for collection.</span>
            </li>
            <li class="checkout-pickup__instruction-item">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <span>Orders not collected within <strong>7 days</strong> of the ready notification may be cancelled.</span>
            </li>
            <li class="checkout-pickup__instruction-item">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <span>For assistance, contact us on
                <a href="https://wa.me/260966755326" style="color:var(--green);">WhatsApp</a>
                or call <strong>+260 966 755 326</strong>.</span>
            </li>
          </ul>
        </div>
        <div class="checkout-pickup__eta">
          <div class="checkout-pickup__eta-icon" aria-hidden="true"><i class="fas fa-clock"></i></div>
          <div>
            <div class="checkout-pickup__eta-label">Estimated Ready Time</div>
            <div class="checkout-pickup__eta-value">2 – 5 business days after payment confirmation</div>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /.checkout-card (pickup) -->


  <!-- ════════════════════
       PAYMENT METHOD CARD — UPDATED
       ════════════════════ -->
  <div class="checkout-card">
    <div class="checkout-card__header">
      <div class="checkout-card__icon" aria-hidden="true">
        <i class="fas fa-credit-card"></i>
      </div>
      <h2 class="checkout-card__title">Payment Method</h2>
    </div>
    <div class="checkout-card__body">
      <div class="checkout-payment">

        <!-- ─── Mobile Money ─── -->
        <div class="payment-category-title">Mobile Money</div>
        <div class="payment-methods-group">

          <!-- MTN -->
          <div class="payment-option">
            <input type="radio" class="payment-option__input" id="pm-mtn"
                   name="paymentMethod" value="mtn">
            <label class="payment-option__label" for="pm-mtn">
              <div class="payment-option__radio" aria-hidden="true"></div>
              <div class="payment-option__icon payment-option__icon--mtn" aria-hidden="true">MTN</div>
              <div class="payment-option__info">
                <div class="payment-option__name">MTN Mobile Money</div>
                <div class="payment-option__desc">
                  A push notification will be sent to your phone to approve payment
                </div>
              </div>
              <span class="payment-option__badge">Recommended</span>
            </label>
          </div>

          <!-- MTN phone input — shown when MTN is selected -->
          <div class="payment-details payment-details--phone" data-payment-details="mtn" style="display:none;">
            <div class="payment-details__title">
              <i class="fas fa-mobile-alt" aria-hidden="true"></i>
              Enter your MTN Mobile Money number
            </div>
            <div class="payment-ref-wrap">
              <label class="payment-ref-label" for="phone-mtn">
                MTN MoMo Number <span style="color:var(--red)">*</span>
              </label>
              <input
                class="payment-ref-input payment-phone-input"
                type="tel"
                id="phone-mtn"
                name="paymentPhone"
                placeholder="+260 96X XXX XXX"
                autocomplete="tel"
                data-provider="mtn"
              >
              <small style="display:block; margin-top:.4rem; color:#64748b; font-size:.8rem;">
                You will receive a push notification to approve the payment on this number.
              </small>
            </div>
          </div>

          <!-- Airtel -->
          <div class="payment-option">
            <input type="radio" class="payment-option__input" id="pm-airtel"
                   name="paymentMethod" value="airtel">
            <label class="payment-option__label" for="pm-airtel">
              <div class="payment-option__radio" aria-hidden="true"></div>
              <div class="payment-option__icon payment-option__icon--airtel" aria-hidden="true">AIRTEL</div>
              <div class="payment-option__info">
                <div class="payment-option__name">Airtel Money</div>
                <div class="payment-option__desc">
                  A push notification will be sent to your phone to approve payment
                </div>
              </div>
            </label>
          </div>

          <!-- Airtel phone input -->
          <div class="payment-details payment-details--phone" data-payment-details="airtel" style="display:none;">
            <div class="payment-details__title">
              <i class="fas fa-mobile-alt" aria-hidden="true"></i>
              Enter your Airtel Money number
            </div>
            <div class="payment-ref-wrap">
              <label class="payment-ref-label" for="phone-airtel">
                Airtel Money Number <span style="color:var(--red)">*</span>
              </label>
              <input
                class="payment-ref-input payment-phone-input"
                type="tel"
                id="phone-airtel"
                name="paymentPhone"
                placeholder="+260 97X XXX XXX"
                autocomplete="tel"
                data-provider="airtel"
              >
              <small style="display:block; margin-top:.4rem; color:#64748b; font-size:.8rem;">
                You will receive a push notification to approve the payment on this number.
              </small>
            </div>
          </div>

        </div><!-- /.payment-methods-group (mobile money) -->

        <!-- ─── Bank & Card ─── -->
        <div class="payment-category-title">Bank & Card</div>
        <div class="payment-methods-group">

          <!-- Bank transfer -->
          <div class="payment-option">
            <input type="radio" class="payment-option__input" id="pm-bank"
                   name="paymentMethod" value="bank">
            <label class="payment-option__label" for="pm-bank">
              <div class="payment-option__radio" aria-hidden="true"></div>
              <div class="payment-option__icon payment-option__icon--bank" aria-hidden="true">
                <i class="fas fa-university"></i>
              </div>
              <div class="payment-option__info">
                <div class="payment-option__name">Bank Transfer</div>
                <div class="payment-option__desc">
                  Pay via bank transfer; we will send you account details after you place the order
                </div>
              </div>
            </label>
          </div>
          <div class="payment-details" data-payment-details="bank" style="display:none;">
            <div class="payment-details__title">
              <i class="fas fa-university" aria-hidden="true"></i>
              Bank transfer details
            </div>
            <p class="payment-details__placeholder">Account details will be sent to your email/phone after you place the order.</p>
          </div>

          <!-- Visa / Mastercard -->
          <div class="payment-option">
            <input type="radio" class="payment-option__input" id="pm-card"
                   name="paymentMethod" value="card">
            <label class="payment-option__label" for="pm-card">
              <div class="payment-option__radio" aria-hidden="true"></div>
              <div class="payment-option__icon payment-option__icon--card" aria-hidden="true">
                <i class="fas fa-credit-card"></i>
              </div>
              <div class="payment-option__info">
                <div class="payment-option__name">Visa / Mastercard</div>
                <div class="payment-option__desc">
                  Pay securely with your debit or credit card
                </div>
              </div>
            </label>
          </div>
          <div class="payment-details" data-payment-details="card" style="display:none;">
            <div class="payment-details__title">
              <i class="fas fa-credit-card" aria-hidden="true"></i>
              Card payment
            </div>
            <p class="payment-details__placeholder">Card payment will be available here. To be implemented.</p>
          </div>

        </div><!-- /.payment-methods-group (bank & card) -->

        <!-- ─── Lenco payment result — shown after API call ─── -->
        <div id="lenco-payment-result" style="display:none;" aria-live="polite">
          <div class="payment-details payment-details--instructions is-visible">
            <div class="payment-details__title">
              <i class="fas fa-info-circle" aria-hidden="true"></i>
              <span id="lenco-instructions-title">Payment Initiated</span>
            </div>
            <div id="lenco-instructions-text" style="margin:.5rem 0; color:#334155; font-size:.9rem;"></div>
            <div id="lenco-polling-status" class="lenco-polling-status">
              <span class="live-dot"></span>
              <span id="lenco-polling-msg">Waiting for payment confirmation…</span>
            </div>
          </div>
        </div>

        <!-- ─── Error message area ─── -->
        <div id="lenco-error" style="display:none;"
             class="checkout-error-msg" role="alert" aria-live="assertive">
          <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
          <span id="lenco-error-text"></span>
        </div>

      </div><!-- /.checkout-payment -->
    </div>
  </div><!-- /.checkout-card (payment) -->

  <!-- Navigation -->
  <div class="checkout-nav-btns">
    <button class="btn btn-back" data-checkout-back type="button">
      <i class="fas fa-arrow-left"></i> Back
    </button>
    <button class="btn btn-place-order" id="place-order-btn" data-place-order type="button">
      <i class="fas fa-lock"></i> Place Order
    </button>
  </div>

</div><!-- /.checkout-panel[step 2] -->


        <!-- ────────────────────────────────────────────────
             STEP 3 — CONFIRMATION
             ──────────────────────────────────────────────── -->
        <div class="checkout-panel" data-step-panel="3" aria-labelledby="step3-title">

          <div class="checkout-card">
            <div class="checkout-card__body" style="padding: 0;">

              <div class="checkout-confirmation">

                <div class="checkout-confirmation__icon" aria-hidden="true">
                  <i class="fas fa-check"></i>
                </div>

                <h2 class="checkout-confirmation__heading" id="step3-title">
                  Order Confirmed!
                </h2>

                <p class="checkout-confirmation__msg">
                  Thank you for your order. We've received your payment details and will
                  notify you via WhatsApp or SMS when your order is ready for pickup.
                </p>

                <!-- Order number (populated by JS) -->
                <div class="checkout-confirmation__order-num" aria-live="polite">
                  <div>
                    <span class="checkout-confirmation__order-label">Order Number</span>
                    <span class="checkout-confirmation__order-val">—</span>
                  </div>
                </div>

                <!-- Dynamic summary (populated by JS) -->
                <div class="checkout-confirmation__summary">
                  <!-- JS renders rows here -->
                </div>

                <!-- Actions -->
                <div class="checkout-confirmation__actions">
                  <a href="{{ url('/shop') }}" class="btn btn-primary">
                    <i class="fas fa-store"></i> Continue Shopping
                  </a>
                  <button class="btn btn-back" onclick="window.print()" type="button">
                    <i class="fas fa-print"></i> Print Receipt
                  </button>
                </div>

              </div><!-- /.checkout-confirmation -->

            </div>
          </div><!-- /.checkout-card (confirmation) -->

        </div><!-- /.checkout-panel[step 3] -->

      </div><!-- /.checkout-main -->


      <!-- ════════════════════════════════════════════════════
           SIDEBAR — ORDER SUMMARY
           ════════════════════════════════════════════════════ -->
      <aside class="checkout-sidebar" aria-label="Order summary">

        <!-- Order Summary -->
        <div class="checkout-summary">
          <h3 class="checkout-summary__title">Order Summary</h3>

          <!-- Items (populated / synced by JS) -->
          <div class="checkout-summary__items">
            @foreach($cartItems as $item)
              @php
                $qty   = (int)($item['qty']   ?? 1);
                $price = (float)($item['price'] ?? 0);
                $name  = htmlspecialchars($item['name']  ?? 'Product');
                $size  = htmlspecialchars($item['size']  ?? '');
                $image = htmlspecialchars($item['image'] ?? '/images/placeholder.jpg');
              @endphp
              <div class="checkout-summary__item">
                <img class="checkout-summary__item-img" src="{{ $image }}" alt="{{ $name }}" loading="lazy">
                <div class="checkout-summary__item-info">
                  <div class="checkout-summary__item-name">{{ $name }}</div>
                  <div class="checkout-summary__item-meta">{{ $size ? 'Size: ' . $size . ' · ' : '' }}Qty {{ $qty }}</div>
                </div>
                <div class="checkout-summary__item-price">K {{ number_format($price * $qty) }}</div>
              </div>
            @endforeach
          </div>

          <!-- Totals -->
          <div class="checkout-summary__row">
            <span>Subtotal</span>
            <span class="checkout-summary__subtotal-val">{{ $subtotal }}</span>
          </div>
          <div class="checkout-summary__row">
            <span>Delivery</span>
            <span style="color:var(--green); font-weight:600;">Free Pickup</span>
          </div>
          <div class="checkout-summary__divider" aria-hidden="true"></div>
          <div class="checkout-summary__row checkout-summary__total-row">
            <span class="checkout-summary__total-label">Total</span>
            <span class="checkout-summary__total-amount checkout-summary__total-val">{{ $total }}</span>
          </div>

          <p class="checkout-summary__note">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            All prices are in Zambian Kwacha (ZMW). Pickup is free at our Lusaka location.
          </p>
        </div><!-- /.checkout-summary -->

        <!-- Trust badges -->
        <div class="checkout-trust" aria-label="Security assurances">
          <div class="checkout-trust__badge">
            <i class="fas fa-shield-alt"></i>
            <span>Secure checkout — your data is safe</span>
          </div>
          <div class="checkout-trust__badge">
            <i class="fas fa-headset"></i>
            <span>Support via WhatsApp: +260 966 755 326</span>
          </div>
          <div class="checkout-trust__badge">
            <i class="fas fa-undo-alt"></i>
            <span>Easy returns on unused items</span>
          </div>
          <div class="checkout-trust__badge">
            <i class="fas fa-map-marker-alt"></i>
            <span>Pickup: COMESA Village, Lusaka</span>
          </div>
        </div>

      </aside><!-- /.checkout-sidebar -->

    </div><!-- /.checkout-layout -->
  </div><!-- /.checkout-section__inner -->
</section><!-- /.checkout-section -->


<!-- Full-page loading overlay -->
<div class="checkout-loading" aria-live="assertive" aria-label="Processing" role="status">
  <div class="checkout-loading__spinner" aria-hidden="true"></div>
  <div class="checkout-loading__msg">Processing…</div>
</div>

@endsection
