@extends('layouts.admin')

@section('content')
@php
$recentActivity = $recentActivity ?? [];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/dashboard') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to dashboard
  </a>
  <h2 class="admin-page-header__heading">Recent Activity</h2>
</div>

<div class="admin-panel" aria-label="All activity">
  <div class="admin-panel__body">
    @if(!empty($recentActivity))
      @foreach($recentActivity as $item)
        <div class="activity-item">
          <div class="activity-icon activity-icon--{{ $item['type'] }}" aria-hidden="true">
            <i class="{{ $item['icon'] }}"></i>
          </div>
          <div class="activity-content">
            <p class="activity-title">{{ $item['title'] }}</p>
            <p class="activity-sub">{{ $item['subtitle'] }}</p>
          </div>
          <time class="activity-time" datetime="{{ $item['isoDate'] }}">{{ $item['timeAgo'] }}</time>
        </div>
      @endforeach
    @else
      <p class="admin-empty__body">No activity yet.</p>
    @endif
  </div>
</div>

@endsection
