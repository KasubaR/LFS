@extends('layouts.app')

@section('content')
@php
  $statusClass = $isActive ? 'active' : (
      $displayStatus === 'Expired' ? 'expired' : 'inactive'
  );
  $satelliteName = $member->satellite?->name ?? '—';
  $expiryLabel = $membership->expiry_date
      ? $membership->expiry_date->format('j M Y')
      : '—';
@endphp

<section class="membership-verify">
  <div class="membership-verify__inner">
    <header class="membership-verify__brand">
      <img
        src="{{ asset('images/Logo/lfs-runners-icon.svg') }}"
        alt=""
        width="48"
        height="48"
        class="membership-verify__logo"
      >
      <div>
        <p class="membership-verify__eyebrow">Lusaka Fitness Squad</p>
        <h1 class="membership-verify__heading">Membership verification</h1>
      </div>
    </header>

    <div class="membership-verify__badge membership-verify__badge--{{ $statusClass }}" role="status">
      {{ $displayStatus }}
    </div>

    <dl class="membership-verify__details">
      <div class="membership-verify__row">
        <dt>Member name</dt>
        <dd>{{ $member->name }}</dd>
      </div>
      <div class="membership-verify__row">
        <dt>Membership number</dt>
        <dd>{{ $membership->membership_number ?? '—' }}</dd>
      </div>
      <div class="membership-verify__row">
        <dt>Membership status</dt>
        <dd>{{ ucfirst(str_replace('_', ' ', (string) $membership->status)) }}</dd>
      </div>
      <div class="membership-verify__row">
        <dt>Expiry date</dt>
        <dd>{{ $expiryLabel }}</dd>
      </div>
      <div class="membership-verify__row">
        <dt>Satellite</dt>
        <dd>{{ $satelliteName }}</dd>
      </div>
    </dl>

    <p class="membership-verify__note">Official LFS digital membership card verification.</p>
  </div>
</section>
@endsection
