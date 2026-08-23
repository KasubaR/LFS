@extends('layouts.app')

@section('content')
@php
  $badge = match ($election->status) {
      'open' => 'active',
      'scheduled', 'ballot_approved' => 'pending',
      'closed', 'certified', 'locked' => 'draft',
      default => 'pending',
  };
@endphp

<x-account-shell
  active-tab="elections"
  :title="$election->title"
  description="Vote and follow this LFS election."
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  <p class="account-backlink">
    <a href="{{ route('account.elections') }}">
      <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to elections
    </a>
  </p>

  <div class="dashboard-col--main">
    <div class="dashboard-card">
      <div class="account-order-detail__header">
        <h2 class="dashboard-card__title">{{ $election->title }}</h2>
        <span class="auth-status-badge auth-status-badge--{{ $badge }}">
          {{ \App\Enums\ElectionStatus::memberLabel($election->status) }}
        </span>
      </div>
    </div>

    <div class="dashboard-card">
      <h2 class="dashboard-card__title"><i class="fas fa-clipboard-check" aria-hidden="true"></i> Meeting attendance</h2>
      <p class="dashboard-empty">Checking in records that you are present at the EGM. It does <strong>not</strong> cast a vote.</p>

      @if($attended)
        <p><i class="fas fa-circle-check" aria-hidden="true" style="color:var(--green);"></i> You are checked in.</p>
      @elseif(($onRoll ?? false) && in_array($election->status, ['scheduled', 'ballot_approved', 'open'], true))
        <form method="post" action="{{ route('account.elections.check-in', $election->id) }}">
          @csrf
          <button type="submit" class="btn btn-primary">Check in to the meeting</button>
        </form>
      @elseif(! ($onRoll ?? false))
        <p class="dashboard-empty">Check-in is only available to members on the voters&rsquo; roll for this meeting.</p>
      @endif
    </div>

    <div class="dashboard-card">
      <h2 class="dashboard-card__title"><i class="fas fa-square-poll-vertical" aria-hidden="true"></i> Your ballots</h2>

      @if($election->status !== 'open')
        <p class="dashboard-empty">Voting is not open{{ $election->isClosedOrLater() ? ' (polls closed).' : ' yet.' }}</p>
      @elseif($entitlements->isEmpty())
        <p class="dashboard-empty">You have no remaining ballot entitlements for this election.</p>
      @else
        @foreach($entitlements as $entitlement)
          <div class="account-ballot-position">
            <h3 class="account-ballot-position__title">{{ $entitlement->position->title }}</h3>
            <p class="account-ballot-position__type">
              @if($entitlement->type === 'proxy')
                Voting by proxy for another member
              @else
                Your direct ballot
              @endif
            </p>

            <form method="post" action="{{ route('account.elections.cast', [$election->id, $entitlement->id]) }}">
              @csrf
              <div class="account-ballot-candidates" role="radiogroup" aria-label="{{ $entitlement->position->title }} candidates">
                @foreach($entitlement->position->candidates->where('is_active', true) as $candidate)
                  <div class="account-ballot-candidate-card">
                    <label class="account-ballot-candidate-card__select">
                      <input type="radio" name="choice" value="candidate:{{ $candidate->id }}" required>
                      <span class="account-ballot-candidate-card__mark" aria-hidden="true">&#10005;</span>
                      <span class="account-ballot-candidate-card__photo-wrap">
                        @if($candidate->photo_url)
                          <img src="{{ $candidate->photo_url }}" alt="" class="account-ballot-candidate-card__photo">
                        @else
                          <span class="account-ballot-candidate-card__initial" aria-hidden="true">{{ strtoupper(substr($candidate->name, 0, 1)) }}</span>
                        @endif
                      </span>
                      <span class="account-ballot-candidate-card__name">{{ $candidate->name }}</span>
                    </label>
                    @if($candidate->photo_url)
                      <button
                        type="button"
                        class="account-ballot-candidate-card__zoom"
                        data-candidate-photo="{{ $candidate->photo_url }}"
                        data-candidate-name="{{ $candidate->name }}"
                        aria-label="View larger photo of {{ $candidate->name }}"
                      >
                        <i class="fas fa-magnifying-glass-plus" aria-hidden="true"></i>
                      </button>
                    @endif
                  </div>
                @endforeach
                @if($entitlement->position->allow_abstain)
                  <div class="account-ballot-candidate-card account-ballot-candidate-card--abstain">
                    <label class="account-ballot-candidate-card__select">
                      <input type="radio" name="choice" value="abstain">
                      <span class="account-ballot-candidate-card__mark" aria-hidden="true">&#10005;</span>
                      <span class="account-ballot-candidate-card__photo-wrap">
                        <span class="account-ballot-candidate-card__initial" aria-hidden="true"><i class="fas fa-slash"></i></span>
                      </span>
                      <span class="account-ballot-candidate-card__name">Abstain</span>
                    </label>
                  </div>
                @endif
              </div>
              @error('choice')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror

              <label class="account-ballot-confirm">
                <input type="checkbox" name="confirm" value="1" required>
                <span>I confirm this is my final choice and cannot be changed after submission.</span>
              </label>
              @error('confirm')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror

              <button type="submit" class="btn btn-primary">Submit ballot</button>
            </form>
          </div>
        @endforeach
      @endif
    </div>

    @if($results)
      <div class="dashboard-card">
        <h2 class="dashboard-card__title"><i class="fas fa-award" aria-hidden="true"></i> Certified results</h2>
        @foreach($results['positions'] as $pos)
          <h3 class="account-ballot-position__title">{{ $pos['title'] }}</h3>
          <ul class="account-results-list">
            @foreach($pos['candidates'] as $c)
              <li class="account-results-list__row"><span>{{ $c['name'] }}</span><span>{{ $c['votes'] }}</span></li>
            @endforeach
            <li class="account-results-list__row"><span>Abstentions</span><span>{{ $pos['abstentions'] }}</span></li>
            <li class="account-results-list__row">
              <span>Winner</span>
              <span class="account-results-list__winner">{{ $pos['winner']['name'] ?? 'Tie / none' }}</span>
            </li>
          </ul>
        @endforeach
      </div>
    @endif

    <div class="dashboard-card">
      <h2 class="dashboard-card__title"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Report a problem</h2>
      <form method="post" action="{{ route('account.elections.complaint', $election->id) }}" class="lfs-form">
        @csrf
        <div class="form-group{{ $errors->has('body') ? ' form-group--error' : '' }}">
          <label for="election-complaint-body">Describe a technical or electoral issue</label>
          <textarea id="election-complaint-body" name="body" rows="3" required>{{ old('body') }}</textarea>
          @error('body')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn btn-primary">Submit complaint</button>
      </form>
    </div>
  </div>

  <div id="candidate-photo-lightbox" class="candidate-photo-lightbox is-hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Candidate photo">
    <button type="button" class="candidate-photo-lightbox__close" data-close-candidate-photo aria-label="Close">
      <i class="fas fa-xmark" aria-hidden="true"></i>
    </button>
    <img src="" alt="" class="candidate-photo-lightbox__img" data-candidate-photo-lightbox-img>
    <p class="candidate-photo-lightbox__caption" data-candidate-photo-lightbox-caption></p>
  </div>
</x-account-shell>
@endsection
