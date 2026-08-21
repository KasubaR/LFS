@props([
    'activeTab' => 'dashboard',
    'title' => 'Account',
    'user' => null,
    'membership' => null,
    'status' => null,
])

@php
  $user = $user ?? auth()->user();
  $avatarUrl = $user?->avatarUrl();
  $initials = $user?->initials() ?? 'LF';
  $statusBadge = 'pending';
  // Pages that don't track a membership in their view data (e.g. elections)
  // pass no `membership` prop at all, so both of these must have a default
  // outside the `if` below rather than only being set inside it.
  $graceReminder = null;
  if ($membership) {
      $statusBadge = match ($membership->status) {
          'active' => 'active',
          'draft' => 'draft',
          // Suspended reuses the "expired" red treatment — visually it's the
          // same "not in good standing" state, just for a different reason.
          'expired', 'cancelled', 'suspended' => 'expired',
          default => 'pending',
      };

  // A non-blocking "balance due by 30 April" reminder during the grace
  // period — PartiallyPaid-but-still-Active memberships get full account
  // access (no gate), so this is purely informational.
  $graceReminder = $membership->status === 'active'
      ? app(\App\Services\MembershipService::class)->findGracePeriodBalanceReminder($user->id)
      : null;
  }

  $tabLabels = [
      'dashboard' => 'Dashboard',
      'orders' => 'Orders',
      'wishlist' => 'Wishlist',
      'payments' => 'Payments',
      'documents' => 'Documents',
      'elections' => 'Elections',
      'settings' => 'Settings',
  ];
  $tabRoutes = [
      'dashboard' => 'account',
      'orders' => 'account.orders',
      'wishlist' => 'account.wishlist',
      'payments' => 'account.payments',
      'documents' => 'account.documents',
      'elections' => 'account.elections',
      'settings' => 'account.settings',
  ];
  $tabLabel = $tabLabels[$activeTab] ?? 'Account';
  $showTabCrumb = $activeTab !== 'dashboard';
  $deepTitle = $title;
  $defaultTitles = ['Account', 'My Dashboard', 'My Orders', 'My Wishlist', 'Payment History', 'Document Library', 'Account Settings', $tabLabel];
  $showDeepCrumb = $deepTitle !== '' && ! in_array($deepTitle, $defaultTitles, true);
@endphp

<section class="account-breadcrumb-bar">
  <div class="account-breadcrumb-bar__inner">
    <nav class="page-breadcrumb" aria-label="Breadcrumb">
      <ol>
        <li><a href="{{ url('/') }}">Home</a></li>
        <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
        @if($showDeepCrumb)
          <li><a href="{{ route('account') }}">Account</a></li>
          <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
          @if($showTabCrumb)
            <li><a href="{{ route($tabRoutes[$activeTab] ?? 'account') }}">{{ $tabLabel }}</a></li>
            <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
          @endif
          <li aria-current="page">{{ $deepTitle }}</li>
        @elseif($showTabCrumb)
          <li><a href="{{ route('account') }}">Account</a></li>
          <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
          <li aria-current="page">{{ $tabLabel }}</li>
        @else
          <li aria-current="page">Account</li>
        @endif
      </ol>
    </nav>
  </div>
</section>

<section class="dashboard-section account-section">
  <div class="dashboard-section__inner">
    <section class="account-profile-card" aria-labelledby="account-profile-title">
      <div class="account-profile-card__top">
        <div class="account-profile-card__content">
          <p class="account-profile-card__eyebrow">Membership</p>
          <div class="account-profile-card__identity">
            <div class="account-profile-card__avatar" aria-hidden="true">
              @if($avatarUrl)
                <img src="{{ $avatarUrl }}" alt="" class="account-profile-card__avatar-img" width="72" height="72">
              @else
                <span class="account-profile-card__avatar-initials">{{ $initials }}</span>
              @endif
            </div>
            <div class="account-profile-card__identity-copy">
              <div class="account-profile-card__heading">
                <h1 class="account-profile-card__name" id="account-profile-title">{{ $user->name }}</h1>
                @if($membership)
                  <span class="auth-status-badge auth-status-badge--{{ $statusBadge }} account-profile-card__status">
                    @if($membership->status === 'active')
                      <i class="fas fa-circle-check" aria-hidden="true"></i>
                    @endif
                    {{ str_replace('_', ' ', $membership->status) }}
                  </span>
                @endif
              </div>

              <div class="account-profile-card__contacts">
                <a href="mailto:{{ $user->email }}" class="account-profile-card__contact">
                  <i class="fas fa-envelope" aria-hidden="true"></i>
                  {{ $user->email }}
                </a>
                @if($user->phone)
                  <a href="tel:{{ $user->phone }}" class="account-profile-card__contact">
                    <i class="fas fa-phone" aria-hidden="true"></i>
                    {{ $user->phone }}
                  </a>
                @endif
              </div>
            </div>
          </div>
        </div>

        <div class="account-profile-card__actions">
          <a href="{{ route('account.settings.personal') }}" class="btn btn-primary" data-full-reload>
            <i class="fas fa-user-pen" aria-hidden="true"></i>
            Edit Profile
          </a>
          <form action="{{ route('logout') }}" method="post">
            @csrf
            <button type="submit" class="btn btn-outline account-profile-card__signout">
              <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
              Sign Out
            </button>
          </form>
        </div>
      </div>

      @if($membership)
        <dl class="account-profile-card__meta">
          <div class="account-profile-card__meta-item">
            <dt>Membership number</dt>
            <dd>
              @if($membership->membership_number)
                {{ $membership->membership_number }}
              @else
                <span class="account-profile-card__pending">Pending</span>
              @endif
            </dd>
          </div>
          @if($membership->plan)
            <div class="account-profile-card__meta-item">
              <dt>Membership</dt>
              <dd data-account-plan-name>Annual Membership</dd>
            </div>
          @endif
          @if($membership->expiry_date)
            <div class="account-profile-card__meta-item">
              <dt>Expiry</dt>
              <dd>{{ $membership->expiry_date->format('j M Y') }}</dd>
            </div>
          @endif
          @if($user->satellite)
            <div class="account-profile-card__meta-item">
              <dt>Satellite</dt>
              <dd>{{ $user->satellite->name }}</dd>
            </div>
          @endif
        </dl>
      @else
        <p class="account-profile-card__empty">
          You don't have a membership yet.
          <a href="{{ url('/membership/apply') }}">Start an application</a>.
        </p>
      @endif
    </section>

    <div id="account-tab-panel">
      <x-account-tabs :active-tab="$activeTab" />

      @if(!empty($status))
        <div class="auth-alert auth-alert--success" role="status">
          <i class="fas fa-circle-check" aria-hidden="true"></i>
          <span>{{ $status }}</span>
        </div>
      @endif

      @if($graceReminder)
        @php
          $reminderBalance = number_format((float) $graceReminder['payment']['amount'] - (float) $graceReminder['payment']['amountPaid'], 2);
          $reminderDeadline = $graceReminder['membership']->grace_period_ends_at?->format('j M Y');
        @endphp
        <div class="auth-alert auth-alert--warning" role="status">
          <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
          <span>
            You still owe K{{ $reminderBalance }} on your membership{{ $reminderDeadline ? " — pay in full by {$reminderDeadline} to avoid suspension" : '' }}.
            <a href="{{ route('account.balance') }}">Pay now</a>.
          </span>
        </div>
      @endif

      @if(isset($errors) && $errors->has('_general'))
        <div class="auth-alert auth-alert--error" role="alert">
          <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
          <span>{{ $errors->first('_general') }}</span>
        </div>
      @endif

      {{ $slot }}
    </div>
  </div>
</section>
