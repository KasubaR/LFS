@extends('layouts.admin')

@section('content')
@php
$announcement = $announcement ?? ['title' => '', 'body' => '', 'isActive' => true, 'publishedAt' => now()->format('Y-m-d\TH:i')];
$errors       = $errors       ?? [];
$csrfToken    = $csrfToken    ?? '';
$isEdit       = isset($announcement['id']);
$breadcrumbs  = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Announcements', 'url' => '/admin/announcements'],
    ['label' => $isEdit ? 'Edit' : 'Create'],
];
$formAction   = $isEdit ? '/admin/announcements/' . $announcement['id'] . '/edit' : '/admin/announcements/create';

$publishedAtValue = $announcement['publishedAt'] ?? now()->format('Y-m-d\TH:i');
if ($publishedAtValue !== '' && strlen($publishedAtValue) > 16) {
    $publishedAtValue = date('Y-m-d\TH:i', strtotime($publishedAtValue));
}
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/announcements') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Announcements
  </a>
  <h2 class="admin-page-header__heading">{{ $isEdit ? 'Edit Announcement' : 'Add Announcement' }}</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ $formAction }}">
    <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

    <div class="form-group{{ !empty($errors['title']) ? ' form-group--error' : '' }}">
      <label for="title" class="admin-label">Title</label>
      <input type="text" id="title" name="title" required
             value="{{ $announcement['title'] ?? '' }}"
             class="admin-input">
      @if(!empty($errors['title']))
      <p class="form-group__error" role="alert">{{ $errors['title'] }}</p>
      @endif
    </div>

    <div class="form-group{{ !empty($errors['body']) ? ' form-group--error' : '' }}">
      <label for="body" class="admin-label">Message</label>
      <textarea id="body" name="body" rows="6" required class="admin-input">{{ $announcement['body'] ?? '' }}</textarea>
      @if(!empty($errors['body']))
      <p class="form-group__error" role="alert">{{ $errors['body'] }}</p>
      @endif
    </div>

    <div class="form-group{{ !empty($errors['published_at']) ? ' form-group--error' : '' }}">
      <label for="published_at" class="admin-label">Publish date</label>
      <input type="datetime-local" id="published_at" name="published_at"
             value="{{ $publishedAtValue }}"
             class="admin-input">
      @if(!empty($errors['published_at']))
      <p class="form-group__error" role="alert">{{ $errors['published_at'] }}</p>
      @endif
    </div>

    <div class="form-group">
      <label class="admin-label" style="display:flex; align-items:center; gap:0.5rem;">
        <input type="checkbox" name="is_active" value="1" {{ ($announcement['isActive'] ?? true) ? 'checked' : '' }}>
        Active (visible to members)
      </label>
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">{{ $isEdit ? 'Save changes' : 'Create Announcement' }}</button>
    <a href="{{ url('/admin/announcements') }}" class="admin-btn admin-btn--ghost">Cancel</a>
  </form>
</div>

@endsection
