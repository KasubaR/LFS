@extends('layouts.app')

@section('content')
<x-account-shell
  active-tab="elections"
  title="Elections"
  description="Vote and follow LFS annual and EGM elections."
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  @if($elections->isEmpty())
    <div class="account-empty" role="status">
      <div class="account-empty__icon" aria-hidden="true"><i class="fas fa-check-to-slot"></i></div>
      <h2 class="account-empty__title">No elections right now</h2>
      <p class="account-empty__text">
        You'll see an election here once you're on its voters&rsquo; roll and it moves past the draft stage.
      </p>
    </div>
  @else
    <ul class="account-order-list">
      @foreach($elections as $election)
        @php
          $badge = match ($election->status) {
              'open' => 'active',
              'scheduled', 'ballot_approved' => 'pending',
              'closed', 'certified', 'locked' => 'draft',
              default => 'pending',
          };
        @endphp
        <li class="account-order-card">
          <div class="account-order-card__main">
            <div class="account-order-card__top">
              <a href="{{ route('account.elections.show', $election->id) }}" class="account-order-card__number">
                {{ $election->title }}
              </a>
              <span class="auth-status-badge auth-status-badge--{{ $badge }}">
                {{ \App\Enums\ElectionStatus::memberLabel($election->status) }}
              </span>
            </div>
            @if($election->scheduled_open_at)
              <p class="account-order-card__meta">
                Opens {{ $election->scheduled_open_at->format('j M Y') }}
              </p>
            @endif
          </div>
          <div class="account-order-card__side">
            <a href="{{ route('account.elections.show', $election->id) }}" class="account-order-card__link">
              View ballot
            </a>
          </div>
        </li>
      @endforeach
    </ul>
  @endif
</x-account-shell>
@endsection
