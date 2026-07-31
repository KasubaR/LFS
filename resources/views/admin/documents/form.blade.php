@extends('layouts.admin')

@section('content')
@php
$document = $document ?? [
    'title' => '',
    'category' => 'forms',
    'description' => '',
    'isPublished' => true,
    'sortOrder' => 0,
    'publishedAt' => now()->format('Y-m-d\TH:i'),
];
$categories = $categories ?? [];
$errors = $errors ?? [];
$csrfToken = $csrfToken ?? '';
$isEdit = isset($document['id']);
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Documents', 'url' => '/admin/documents'],
    ['label' => $isEdit ? 'Edit' : 'Upload'],
];
$formAction = $isEdit ? '/admin/documents/'.$document['id'].'/edit' : '/admin/documents/create';

$publishedAtValue = $document['publishedAt'] ?? now()->format('Y-m-d\TH:i');
if ($publishedAtValue !== '' && strlen($publishedAtValue) > 16) {
    $publishedAtValue = date('Y-m-d\TH:i', strtotime($publishedAtValue));
}
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/documents') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Documents
  </a>
  <h2 class="admin-page-header__heading">{{ $isEdit ? 'Edit Document' : 'Upload Document' }}</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ $formAction }}" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

    <div class="form-group{{ $errors->has('title') || !empty($errors['title']) ? ' form-group--error' : '' }}">
      <label for="title" class="admin-label">Title</label>
      <input type="text" id="title" name="title" required
             value="{{ old('title', $document['title'] ?? '') }}"
             class="admin-input">
      @error('title')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('category') ? ' form-group--error' : '' }}">
      <label for="category" class="admin-label">Category</label>
      <select id="category" name="category" required class="admin-input">
        @foreach($categories as $value => $label)
          <option value="{{ $value }}" {{ (string) old('category', $document['category'] ?? '') === (string) $value ? 'selected' : '' }}>
            {{ $label }}
          </option>
        @endforeach
      </select>
      @error('category')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('description') ? ' form-group--error' : '' }}">
      <label for="description" class="admin-label">Description (optional)</label>
      <textarea id="description" name="description" rows="3" class="admin-input">{{ old('description', $document['description'] ?? '') }}</textarea>
      @error('description')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('file') ? ' form-group--error' : '' }}">
      <label for="file" class="admin-label">
        File
        @if($isEdit)
          (leave blank to keep current)
        @endif
      </label>
      @if($isEdit && !empty($document['originalFilename']))
        <p class="admin-help-text">Current file: {{ $document['originalFilename'] }}</p>
      @endif
      <input type="file" id="file" name="file"
             accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
             class="admin-input"
             {{ $isEdit ? '' : 'required' }}>
      <p class="admin-help-text">PDF, DOC, or DOCX. Max 20 MB.</p>
      @error('file')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('sort_order') ? ' form-group--error' : '' }}">
      <label for="sort_order" class="admin-label">Sort order</label>
      <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
             value="{{ old('sort_order', $document['sortOrder'] ?? 0) }}"
             class="admin-input">
      @error('sort_order')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('published_at') ? ' form-group--error' : '' }}">
      <label for="published_at" class="admin-label">Publish date</label>
      <input type="datetime-local" id="published_at" name="published_at"
             value="{{ old('published_at', $publishedAtValue) }}"
             class="admin-input">
      @error('published_at')
        <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group">
      <label class="admin-checkbox">
        <input type="checkbox" name="is_published" value="1" {{ old('is_published', $document['isPublished'] ?? true) ? 'checked' : '' }}>
        Published (visible to paid members)
      </label>
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">{{ $isEdit ? 'Save changes' : 'Upload Document' }}</button>
    <a href="{{ url('/admin/documents') }}" class="admin-btn admin-btn--ghost">Cancel</a>
  </form>
</div>

@endsection
