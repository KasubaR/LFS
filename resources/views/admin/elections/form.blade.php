@extends('layouts.admin')

@section('content')
@php
$types = $types ?? [];
$csrfToken = $csrfToken ?? csrf_token();
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Elections', 'url' => '/admin/elections'],
    ['label' => 'Create'],
];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/elections') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Elections
  </a>
  <h2 class="admin-page-header__heading">Create election</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ url('/admin/elections/create') }}">
    @csrf
    <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

    <div class="form-group{{ $errors->has('title') ? ' form-group--error' : '' }}">
      <label for="title" class="admin-label">
        Title <span class="admin-label__required">*</span>
      </label>
      <input
        type="text"
        id="title"
        name="title"
        required
        value="{{ old('title') }}"
        class="admin-input"
        placeholder="e.g. 2026 EGM By-election"
      >
      @error('title')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('type') ? ' form-group--error' : '' }}">
      <label for="type" class="admin-label">
        Type <span class="admin-label__required">*</span>
      </label>
      <select id="type" name="type" required class="admin-input">
        @foreach($types as $value => $label)
          <option value="{{ $value }}" @selected(old('type', 'by_election') === $value)>
            {{ $label }}
          </option>
        @endforeach
      </select>
      @error('type')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('description') ? ' form-group--error' : '' }}">
      <label for="description" class="admin-label">Description</label>
      <textarea
        id="description"
        name="description"
        rows="4"
        class="admin-input"
        placeholder="Optional notes for the Electoral Commission"
      >{{ old('description') }}</textarea>
      @error('description')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('scheduled_open_at') ? ' form-group--error' : '' }}">
      <label for="scheduled_open_at" class="admin-label">Scheduled open</label>
      <input
        type="datetime-local"
        id="scheduled_open_at"
        name="scheduled_open_at"
        value="{{ old('scheduled_open_at') }}"
        class="admin-input"
      >
      @error('scheduled_open_at')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('scheduled_close_at') ? ' form-group--error' : '' }}">
      <label for="scheduled_close_at" class="admin-label">Scheduled close</label>
      <input
        type="datetime-local"
        id="scheduled_close_at"
        name="scheduled_close_at"
        value="{{ old('scheduled_close_at') }}"
        class="admin-input"
      >
      @error('scheduled_close_at')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('quorum_percent') ? ' form-group--error' : '' }}">
      <label for="quorum_percent" class="admin-label">Quorum %</label>
      <input
        type="number"
        id="quorum_percent"
        name="quorum_percent"
        min="1"
        max="100"
        value="{{ old('quorum_percent', 50) }}"
        class="admin-input"
      >
      <p class="form-group__hint">Default is 50% of members on the locked voters’ roll.</p>
      @error('quorum_percent')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="admin-form-actions">
      <button type="submit" class="admin-btn admin-btn--primary">
        <i class="fas fa-plus" aria-hidden="true"></i>
        Create election
      </button>
      <a href="{{ url('/admin/elections') }}" class="admin-btn admin-btn--ghost">Cancel</a>
    </div>
  </form>
</div>
@endsection
