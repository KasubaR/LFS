@extends('layouts.admin')

@section('content')
@php
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Shop', 'url' => '/admin/products'],
    ['label' => 'Categories', 'url' => '/admin/products/categories'],
    ['label' => 'Edit'],
];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/products/categories') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to categories
  </a>
  <h2 class="admin-page-header__heading">Edit category</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ url('/admin/products/categories/'.$category['id'].'/edit') }}">
    @csrf

    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}">
      <label for="name" class="admin-label">Name</label>
      <input type="text" id="name" name="name" required maxlength="100"
             value="{{ old('name', $category['name']) }}" class="admin-input">
      @error('name')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
      <p class="admin-help-text">Slug <code>{{ $category['slug'] }}</code> stays the same, so existing links and products are unaffected.</p>
    </div>

    <div class="form-group">
      <label for="sort_order" class="admin-label">Order (lowest first)</label>
      <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
             value="{{ old('sort_order', $category['sort_order']) }}" class="admin-input admin-input--number">
    </div>

    <div class="form-group">
      <label class="admin-label" style="display:flex;align-items:center;gap:0.5rem;">
        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $category['is_active'] ? '1' : '') ? 'checked' : '' }}>
        Active (offered when adding or editing products)
      </label>
      <p class="admin-help-text">Hiding a category keeps its existing products visible in the shop.</p>
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
    <a href="{{ url('/admin/products/categories') }}" class="admin-btn admin-btn--ghost">Cancel</a>
  </form>
</div>

@endsection
