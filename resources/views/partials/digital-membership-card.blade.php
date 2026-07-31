@php
  $hasCard = $hasCard ?? false;
  $qrSvg = $qrSvg ?? null;
  $displayStatus = $displayStatus ?? 'Inactive';
  $isCardActive = $isCardActive ?? false;
  $statusClass = $isCardActive ? 'active' : (
      $displayStatus === 'Expired' ? 'expired' : 'inactive'
  );
  $satelliteName = $user->satellite?->name ?? '—';
  $expiryLabel = ($membership && $membership->expiry_date)
      ? $membership->expiry_date->format('j M Y')
      : '—';
  $avatarUrl = $user->avatarUrl();
  $initials = $user->initials();
@endphp

@if($hasCard && $membership && $qrSvg)
  <article class="digital-membership-card" aria-label="Digital membership card">
    <header class="digital-membership-card__brand">
      <div class="digital-membership-card__logo-wrap">
        <img
          src="{{ asset('images/Logo/lfs-runners-icon.svg') }}"
          alt=""
          class="digital-membership-card__logo"
          width="40"
          height="40"
        >
      </div>
      <div>
        <p class="digital-membership-card__eyebrow">Lusaka Fitness Squad</p>
        <h2 class="digital-membership-card__title">Member Card</h2>
      </div>
    </header>

    <div class="digital-membership-card__identity">
      <div class="digital-membership-card__avatar" aria-hidden="true">
        @if($avatarUrl)
          <img src="{{ $avatarUrl }}" alt="" class="digital-membership-card__avatar-img" width="56" height="56">
        @else
          <span class="digital-membership-card__avatar-initials">{{ $initials }}</span>
        @endif
      </div>
      <div>
        <p class="digital-membership-card__name">{{ $user->name }}</p>
        <p class="digital-membership-card__number">{{ $membership->membership_number }}</p>
      </div>
    </div>

    <dl class="digital-membership-card__meta">
      <div>
        <dt>Status</dt>
        <dd>
          <span class="digital-membership-card__status digital-membership-card__status--{{ $statusClass }}">
            {{ $displayStatus }}
          </span>
        </dd>
      </div>
      <div>
        <dt>Expiry</dt>
        <dd>{{ $expiryLabel }}</dd>
      </div>
      <div>
        <dt>Satellite</dt>
        <dd>{{ $satelliteName }}</dd>
      </div>
      <div>
        <dt>Plan</dt>
        <dd>{{ $membership->plan?->name ?? '—' }}</dd>
      </div>
    </dl>

    <div class="digital-membership-card__qr" aria-hidden="true">
      {!! $qrSvg !!}
    </div>
    <p class="digital-membership-card__qr-caption">
      Scan to verify membership
    </p>
  </article>

  <div class="membership-card-lightbox__toolbar">
    <a href="{{ route('account.card.pdf') }}" class="btn btn-primary w-full justify-center" data-full-reload>
      <i class="fas fa-download mr-2" aria-hidden="true"></i> Save as PDF
    </a>
  </div>
@else
  <div class="membership-card-empty membership-card-empty--lightbox">
    <h2 class="membership-card-empty__title">Card not ready yet</h2>
    <p class="membership-card-empty__text">
      Your digital membership card and QR code appear once your membership number
      is issued — usually right after payment is confirmed.
    </p>
  </div>
@endif
