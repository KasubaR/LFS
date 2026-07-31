@extends('layouts.admin')

@section('content')
@php
$importStatus = session('import_status');
$importError  = session('import_error');

$user        = $member['user'];
$memberships = $member['memberships'];
$history     = $member['history'];

$statusBadge = fn (string $status) => match ($status) {
    'active' => 'green',
    'draft', 'pending_payment' => 'orange',
    'expired', 'cancelled' => 'red',
    default => 'muted',
};

$paymentBadge = fn (?string $status) => match ($status) {
    'paid' => 'green',
    'pending', 'partially_paid' => 'orange',
    'failed', 'refunded' => 'red',
    default => 'muted',
};

$cancellableStatuses = ['draft', 'pending_payment', 'active'];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/members') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to members
  </a>
  <h2 class="admin-page-header__heading">{{ $user['name'] }}</h2>
</div>

@if($importStatus)
  <div class="sys-notif sys-notif--info" role="alert" style="margin-bottom:1rem;">
    <i class="fas fa-circle-check" aria-hidden="true"></i>
    <span>{{ $importStatus }}</span>
  </div>
@endif
@if($importError)
  <div class="sys-notif sys-notif--error" role="alert" style="margin-bottom:1rem;">
    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
    <span>{{ $importError }}</span>
  </div>
@endif

<div class="order-detail">

  <!-- ── Left: member details + memberships ─────────────────────────── -->
  <div class="order-detail__main">

    <div class="admin-card">
      <p class="admin-card__title">Member details</p>
      <table class="admin-table admin-table--compact">
        <tbody>
          <tr><th>Name</th> <td>{{ $user['name'] }}</td></tr>
          <tr>
            <th>Email</th>
            <td><a class="message-view__link" href="mailto:{{ $user['email'] }}">{{ $user['email'] }}</a></td>
          </tr>
          @if(!empty($user['phone']))
          <tr>
            <th>Phone</th>
            <td><a class="message-view__link" href="tel:{{ $user['phone'] }}">{{ $user['phone'] }}</a></td>
          </tr>
          @endif
          @if(!empty($user['gender']))
          <tr><th>Sex</th> <td>{{ ucfirst(str_replace('_', ' ', $user['gender'])) }}</td></tr>
          @endif
          @if(!empty($user['nationality']))
          <tr><th>Nationality</th> <td>{{ $user['nationality'] }}</td></tr>
          @endif
          @if(!empty($user['tShirtSize']))
          <tr><th>T-shirt size</th> <td>{{ $user['tShirtSize'] }}</td></tr>
          @endif
          @if(!empty($user['town']))
          <tr><th>Town</th> <td>{{ $user['town'] }}</td></tr>
          @endif
          @if(!empty($user['satellite']))
          <tr><th>Satellite</th> <td>{{ $user['satellite'] }}</td></tr>
          @endif
          @if(!empty($user['registeredAt']))
          <tr><th>Registered</th> <td>{{ date('d M Y, H:i', strtotime($user['registeredAt'])) }}</td></tr>
          @endif
        </tbody>
      </table>
    </div>

    <div class="admin-card">
      <p class="admin-card__title">Memberships</p>
      @if(!empty($memberships))
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Number</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Coverage</th>
                <th>Payment</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($memberships as $m)
              <tr>
                <td>{{ $m['membershipNumber'] ?? 'Pending' }}</td>
                <td>{{ $m['plan']['name'] ?? '—' }}</td>
                <td>
                  <span class="status-pill status-pill--{{ ($statusBadge)($m['status']) }}">
                    {{ ucfirst(str_replace('_', ' ', $m['status'])) }}
                  </span>
                </td>
                <td>
                  @if($m['startDate'] && $m['expiryDate'])
                    {{ date('d M Y', strtotime($m['startDate'])) }} – {{ date('d M Y', strtotime($m['expiryDate'])) }}
                  @else
                    —
                  @endif
                </td>
                <td>
                  @if($m['latestPayment'])
                    <span class="status-pill status-pill--{{ ($paymentBadge)($m['latestPayment']['status']) }}">
                      {{ ucfirst(str_replace('_', ' ', $m['latestPayment']['status'])) }}
                    </span>
                  @else
                    —
                  @endif
                </td>
                <td>
                  @if(in_array($m['status'], $cancellableStatuses, true))
                    <form method="post" action="{{ url('/admin/members/'.$user['id'].'/memberships/'.$m['id'].'/cancel') }}"
                          style="display:flex; gap:0.4rem; align-items:center;"
                          onclick="return confirm('Cancel this membership? This cannot be undone.')">
                      @csrf
                      <input type="text" name="reason" placeholder="Reason (optional)"
                             style="padding:0.35rem 0.55rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:6px; color:var(--off-white); font-size:0.8rem; width:140px;">
                      <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm">
                        Cancel
                      </button>
                    </form>
                  @else
                    —
                  @endif
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @else
        <p class="admin-empty__body">No membership records.</p>
      @endif
    </div>

  </div><!-- /.order-detail__main -->

  <!-- ── Right: history timeline ──────────────────────────────── -->
  <div class="order-detail__aside">

    <div class="admin-card">
      <p class="admin-card__title">History</p>
      @if(!empty($history))
        <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0.85rem;">
          @foreach($history as $entry)
            <li style="border-left:2px solid var(--border-mid); padding-left:0.75rem;">
              <div style="font-size:0.85rem; color:var(--off-white); font-weight:600;">
                {{ ucfirst(str_replace('_', ' ', $entry['event'])) }}
              </div>
              @if($entry['fromStatus'] || $entry['toStatus'])
                <div style="font-size:0.78rem; color:var(--text-dim);">
                  {{ $entry['fromStatus'] ? ucfirst(str_replace('_', ' ', $entry['fromStatus'])) : 'new' }}
                  →
                  {{ $entry['toStatus'] ? ucfirst(str_replace('_', ' ', $entry['toStatus'])) : '—' }}
                </div>
              @endif
              @if(!empty($entry['notes']))
                <div style="font-size:0.78rem; color:var(--text-dim);">{{ $entry['notes'] }}</div>
              @endif
              <div style="font-size:0.72rem; color:var(--text-dim); margin-top:0.2rem;">
                {{ date('d M Y, H:i', strtotime($entry['createdAt'])) }} · {{ $entry['actor'] ?? 'system' }}
              </div>
            </li>
          @endforeach
        </ul>
      @else
        <p class="admin-empty__body">No history recorded yet.</p>
      @endif
    </div>

  </div><!-- /.order-detail__aside -->

</div><!-- /.order-detail -->

@endsection
