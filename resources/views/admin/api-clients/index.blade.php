@extends('layouts.admin')

@section('content')
@php
$clients = $clients ?? [];
$scopeLabels = $scopeLabels ?? [];
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">API Keys</h2>
  <a href="/admin/api-clients/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> Issue API Key
  </a>
</div>

<p class="admin-empty" style="text-align:left;background:none;padding:0 0 1rem;">
  Keys let LFS event websites check whether a registrant is a paid-up member, so they can apply
  the member discount at checkout. Event sites never receive the member list &mdash; they ask
  about one person at a time.
</p>

@if(!empty($newToken))
  <div class="admin-form-card" style="margin-bottom:1.5rem;border-left:4px solid #16a34a;">
    <p><strong>API key issued for {{ $newTokenName }}.</strong></p>
    <p>Copy it now &mdash; only a hash is stored, so it cannot be shown again. If it is lost, rotate the key.</p>
    <pre style="user-select:all;word-break:break-all;white-space:pre-wrap;padding:.75rem;background:#111827;color:#4ade80;border-radius:6px;font-size:.85rem;">{{ $newToken }}</pre>
    <p style="margin-top:.5rem;">Send it to the event site developer over a secure channel, not plain email.</p>
  </div>
@endif

@if(count($clients) === 0)
  <p class="admin-empty">No API keys yet. Issue one per event website that needs to verify members.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Event site</th>
          <th>Key ID</th>
          <th>Scopes</th>
          <th>Status</th>
          <th>Last used</th>
          <th>Requests (30d)</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($clients as $client)
        <tr>
          <td>
            {{ $client->name }}
            @if($client->contact_email)
              <br><small class="text-muted">{{ $client->contact_email }}</small>
            @endif
          </td>
          <td><code>{{ $client->key_id }}</code></td>
          <td>
            @foreach($client->scopes ?? [] as $scope)
              <span class="status-pill status-pill--muted" title="{{ $scopeLabels[$scope] ?? $scope }}">{{ $scope }}</span>
            @endforeach
          </td>
          <td>
            @if($client->isRevoked())
              <span class="status-pill status-pill--red">Revoked</span>
            @elseif($client->isExpired())
              <span class="status-pill status-pill--muted">Expired</span>
            @else
              <span class="status-pill status-pill--green">Active</span>
            @endif
          </td>
          <td>{{ $client->last_used_at?->diffForHumans() ?? 'Never' }}</td>
          <td>{{ number_format($client->requests_last_30_days ?? 0) }}</td>
          <td class="cell-actions">
            @if($client->isRevoked())
              <form method="post" action="/admin/api-clients/{{ $client->id }}/restore">
                @csrf
                <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Restore</button>
              </form>
            @else
              <form method="post" action="/admin/api-clients/{{ $client->id }}/rotate"
                    onsubmit="return confirm('Rotate this key? The current key stops working immediately and the event site must be updated.');">
                @csrf
                <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Rotate</button>
              </form>
              <form method="post" action="/admin/api-clients/{{ $client->id }}/revoke"
                    onsubmit="return confirm('Revoke this key? The event site will stop being able to verify members.');">
                @csrf
                <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm">Revoke</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

@endsection
