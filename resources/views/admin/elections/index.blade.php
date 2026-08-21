@extends('layouts.admin')

@section('content')
@php
$elections = $elections ?? [];
$canWrite = $canWrite ?? false;
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">Elections</h2>
  @if($canWrite)
  <a href="/admin/elections/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> Create election
  </a>
  @endif
</div>

@if(session('flash.success'))
  <p class="admin-flash admin-flash--success">{{ session('flash.success') }}</p>
@endif

@if($elections->isEmpty())
  <p class="admin-empty">No elections yet.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Type</th>
          <th>Status</th>
          <th>Scheduled open</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($elections as $election)
        <tr>
          <td>{{ $election->title }}</td>
          <td>{{ \App\Enums\ElectionType::label($election->type) }}</td>
          <td><span class="status-pill">{{ \App\Enums\ElectionStatus::label($election->status) }}</span></td>
          <td>{{ $election->scheduled_open_at?->format('Y-m-d H:i') ?? '—' }}</td>
          <td class="cell-actions">
            <a href="/admin/elections/{{ $election->id }}" class="admin-btn admin-btn--primary admin-btn--sm">Manage</a>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
@endsection
