@extends('layouts.app')

@section('content')
@php
  $announcements      = $announcements      ?? [];
  $upcomingEvents     = $upcomingEvents     ?? [];
  $hasPaidMembership  = $hasPaidMembership  ?? false;
@endphp

<x-account-shell
  active-tab="dashboard"
  title="My Dashboard"
  :user="$user"
  :membership="$membership"
  :status="$status ?? null"
>
  <div class="dashboard-grid">
    <div class="dashboard-col dashboard-col--main">
      @if($canContinuePayment || $canRenew || ($membership && $membership->status === 'cancelled'))
        <div class="dashboard-card dashboard-card--action">
          <h2 class="dashboard-card__title">Membership action</h2>

          @if($canContinuePayment)
            <div class="auth-account-card auth-account-card--payment">
              <p class="auth-account-card__payment-text">
                Your membership application is ready. Pay with Mobile Money to activate your membership.
              </p>

              <p class="auth-account-card__payment-text">
                Selected plan: <strong>{{ $membership->plan?->name ?? '—' }}</strong>
                (K{{ number_format((float) ($membership->plan?->price ?? 0)) }})
              </p>

              @if(count($changePlans ?? []) > 1)
                <div class="account-change-plan">
                  <p class="auth-account-card__payment-text" style="margin-bottom:0.5rem;">
                    <strong>Change plan</strong> — select a different plan below to switch instantly.
                  </p>
                  <form action="{{ url('/account/plan/change') }}" method="post" class="space-y-4" data-auto-submit-on-select>
                    @csrf
                    <div class="form-group{{ $errors->has('plan_id') ? ' form-group--error' : '' }}">
                      <div class="auth-plan-grid" role="radiogroup" aria-label="Membership plan">
                        @foreach($changePlans as $plan)
                          <label class="auth-plan-option">
                            <input type="radio" name="plan_id" value="{{ $plan['id'] }}"
                              {{ (string) old('plan_id', (string) $membership->current_plan_id) === (string) $plan['id'] ? 'checked' : '' }} required>
                            <span class="auth-plan-option__card">
                              <span>
                                <span class="auth-plan-option__name">{{ $plan['name'] }}</span>
                                <span class="auth-plan-option__meta">{{ $plan['durationMonths'] }} months</span>
                              </span>
                              <span class="auth-plan-option__price">K{{ number_format($plan['price']) }}</span>
                            </span>
                          </label>
                        @endforeach
                      </div>
                      @error('plan_id')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <noscript>
                      <button type="submit" class="btn btn-outline w-full justify-center">
                        <i class="fas fa-rotate mr-2" aria-hidden="true"></i> Update Plan
                      </button>
                    </noscript>
                  </form>
                </div>
                <script>
                  document.querySelectorAll('form[data-auto-submit-on-select] input[name="plan_id"]').forEach((radio) => {
                    radio.addEventListener('change', () => radio.form.requestSubmit());
                  });
                </script>
              @endif

              <form id="account-payment-form" data-amount="{{ $membership->plan?->price ?? 0 }}">
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
                  <i class="fas fa-credit-card mr-2" aria-hidden="true"></i> Pay K{{ number_format((float) ($membership->plan?->price ?? 0)) }}
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
          @endif

          @if($membership && $membership->status === 'cancelled')
            <p class="auth-account-card__empty-text account-inline-note">
              This membership was cancelled.
              <a href="{{ url('/membership/apply') }}" class="auth-account-card__link">Start a new application</a>.
            </p>
          @endif

          @if($canRenew)
            <div class="auth-account-card auth-renewal-panel">
              <p class="auth-renewal-panel__text">
                Your membership has expired. Choose a plan to renew and continue to payment.
              </p>
              <form action="{{ url('/account/renew') }}" method="post" class="space-y-4">
                @csrf
                <div class="form-group{{ $errors->has('plan_id') ? ' form-group--error' : '' }}">
                  <div class="auth-plan-grid" role="radiogroup" aria-label="Renewal plan">
                    @foreach($renewalPlans as $plan)
                      <label class="auth-plan-option">
                        <input type="radio" name="plan_id" value="{{ $plan['id'] }}"
                          {{ (string) old('plan_id', $renewalPlans[0]['id'] ?? '') === (string) $plan['id'] ? 'checked' : '' }} required>
                        <span class="auth-plan-option__card">
                          <span>
                            <span class="auth-plan-option__name">{{ $plan['name'] }}</span>
                            <span class="auth-plan-option__meta">{{ $plan['durationMonths'] }} months</span>
                          </span>
                          <span class="auth-plan-option__price">K{{ number_format($plan['price']) }}</span>
                        </span>
                      </label>
                    @endforeach
                  </div>
                  @error('plan_id')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn btn-primary w-full justify-center">
                  <i class="fas fa-rotate mr-2" aria-hidden="true"></i> Renew Membership
                </button>
              </form>
            </div>
          @endif
        </div>
      @endif

      <div class="dashboard-card">
        <h2 class="dashboard-card__title"><i class="fas fa-bullhorn" aria-hidden="true"></i> Announcements</h2>

        @if(!$hasPaidMembership)
          <p class="dashboard-empty">Announcements unlock once your membership payment is confirmed.</p>
        @elseif(empty($announcements))
          <p class="dashboard-empty">No announcements right now — check back soon.</p>
        @else
          <ul class="dashboard-announcement-list">
            @foreach($announcements as $announcement)
              <li class="dashboard-announcement">
                <div class="dashboard-announcement__title">{{ $announcement['title'] }}</div>
                <div class="dashboard-announcement__body">{{ $announcement['body'] }}</div>
                <div class="dashboard-announcement__date">
                  {{ date('j M Y', strtotime($announcement['publishedAt'])) }}
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      </div>

      <div class="dashboard-card">
        <h2 class="dashboard-card__title"><i class="fas fa-calendar-days" aria-hidden="true"></i> Upcoming Events</h2>

        @if(!$hasPaidMembership)
          <p class="dashboard-empty">Upcoming events unlock once your membership payment is confirmed.</p>
        @elseif(empty($upcomingEvents))
          <p class="dashboard-empty">
            You're not registered for any upcoming events.
            <a href="{{ url('/events') }}" class="auth-account-card__link">Browse events</a>.
          </p>
        @else
          <ul class="dashboard-event-list">
            @foreach($upcomingEvents as $event)
              <li class="dashboard-event">
                <div class="dashboard-event__title">{{ $event['title'] }}</div>
                <div class="dashboard-event__meta">
                  <i class="fas fa-calendar-day" aria-hidden="true"></i>
                  {{ date('j M Y', strtotime($event['eventDate'])) }}
                  @if(!empty($event['location']))
                    &nbsp;·&nbsp;<i class="fas fa-location-dot" aria-hidden="true"></i> {{ $event['location'] }}
                  @endif
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      </div>
    </div>

    <div class="dashboard-col dashboard-col--side">
      <div class="dashboard-card dashboard-card-cta">
        <h2 class="dashboard-card__title"><i class="fas fa-id-card" aria-hidden="true"></i> Membership Card</h2>
        <p class="dashboard-card-cta__text">
          Show your digital membership card with QR code at conferences and events.
        </p>
        <button type="button" class="btn btn-primary w-full justify-center dashboard-card-cta__button" data-open-membership-card>
          <i class="fas fa-qrcode mr-2" aria-hidden="true"></i> View membership card
        </button>
      </div>

      <div class="dashboard-card dashboard-contact-card">
        <h2 class="dashboard-card__title"><i class="fas fa-headset" aria-hidden="true"></i> Contact LFS</h2>
        <p class="dashboard-empty">Need help with membership, events, or the shop? Reach the team directly.</p>

        <ul class="dashboard-contact-list">
          <li class="dashboard-contact-item">
            <span class="dashboard-contact-item__icon" aria-hidden="true"><i class="fas fa-envelope"></i></span>
            <div>
              <div class="dashboard-contact-item__label">Email</div>
              <a href="mailto:info@lfszambia.run" class="dashboard-contact-item__value">info@lfszambia.run</a>
            </div>
          </li>
          <li class="dashboard-contact-item">
            <span class="dashboard-contact-item__icon" aria-hidden="true"><i class="fas fa-phone"></i></span>
            <div>
              <div class="dashboard-contact-item__label">Phone</div>
              <a href="tel:+260767023948" class="dashboard-contact-item__value">+260 767 023 948</a>
            </div>
          </li>
          <li class="dashboard-contact-item">
            <span class="dashboard-contact-item__icon" aria-hidden="true"><i class="fab fa-whatsapp"></i></span>
            <div>
              <div class="dashboard-contact-item__label">WhatsApp</div>
              <a href="https://wa.me/260767023948" class="dashboard-contact-item__value" target="_blank" rel="noopener">Message LFS</a>
            </div>
          </li>
          <li class="dashboard-contact-item">
            <span class="dashboard-contact-item__icon" aria-hidden="true"><i class="fas fa-location-dot"></i></span>
            <div>
              <div class="dashboard-contact-item__label">Address</div>
              <div class="dashboard-contact-item__value">CV-6 COMESA Village, Lusaka Showgrounds</div>
            </div>
          </li>
        </ul>

        <a href="{{ url('/contact-us') }}" class="btn btn-outline w-full justify-center dashboard-contact-card__cta">
          <i class="fas fa-paper-plane mr-2" aria-hidden="true"></i> Send a message
        </a>
      </div>
    </div>
  </div>

  <div
    id="membership-card-lightbox"
    class="membership-card-lightbox is-hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="membership-card-lightbox-title"
    aria-hidden="true"
  >
    <button type="button" class="membership-card-lightbox__backdrop" data-close-membership-card aria-label="Close membership card"></button>
    <div class="membership-card-lightbox__dialog">
      <div class="membership-card-lightbox__header">
        <h2 id="membership-card-lightbox-title" class="membership-card-lightbox__heading">Membership card</h2>
        <button type="button" class="membership-card-lightbox__close" data-close-membership-card aria-label="Close">
          <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
      <div class="membership-card-lightbox__body">
        @include('partials.digital-membership-card')
      </div>
    </div>
  </div>
</x-account-shell>
@endsection
