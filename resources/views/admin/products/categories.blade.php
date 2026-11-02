@extends('layouts.admin')

@section('content')
@php
$categories  = $categories ?? [];
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Shop', 'url' => '/admin/products'],
    ['label' => 'Categories'],
];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/products') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to products
  </a>
  <h2 class="admin-page-header__heading">Product categories</h2>
</div>

<div class="admin-form-card" style="margin-bottom:1.5rem;">
  <form method="post" action="{{ url('/admin/products/categories') }}" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
    @csrf
    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}" style="flex:1 1 220px;margin:0;">
      <label for="name" class="admin-label">New category name</label>
      <input type="text" id="name" name="name" required maxlength="100" value="{{ old('name') }}"
             placeholder="e.g. Hoodies" class="admin-input">
      @error('name')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="form-group" style="width:120px;margin:0;">
      <label for="sort_order" class="admin-label">Order</label>
      <input type="number" id="sort_order" name="sort_order" min="0" max="9999" value="{{ old('sort_order') }}"
             placeholder="last" class="admin-input">
    </div>
    <button type="submit" class="admin-btn admin-btn--primary">
      <i class="fas fa-plus" aria-hidden="true"></i> Add category
    </button>
  </form>
</div>

@if(empty($categories))
  <p class="admin-empty">No categories yet.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Products</th>
          <th>Order</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($categories as $c)
        <tr>
          <td>
            <strong>{{ $c['name'] }}</strong>
            <div class="admin-table__sub"><span>Slug: <code>{{ $c['slug'] }}</code></span></div>
          </td>
          <td>{{ $c['productCount'] }}</td>
          <td>{{ $c['sortOrder'] }}</td>
          <td>
            @if($c['isActive'])
              <span class="status-pill status-pill--green">Active</span>
            @else
              <span class="status-pill status-pill--muted">Hidden</span>
            @endif
          </td>
          <td>
            <a href="/admin/products/categories/{{ $c['id'] }}/edit" class="admin-btn admin-btn--primary admin-btn--sm">Edit</a>
            <form method="post" action="/admin/products/categories/{{ $c['id'] }}/delete" class="admin-inline-form"
                  onsubmit="return confirm('Delete the category &quot;{{ addslashes($c['name']) }}&quot;?');">
              @csrf
              <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm"
                      {{ $c['productCount'] > 0 ? 'disabled title=In&nbsp;use' : '' }}>Delete</button>
            </form>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <p class="admin-help-text" style="margin-top:0.75rem;">
    Categories with products can't be deleted — move the products first, or hide the category.
    Renaming a category keeps its slug, so existing shop links keep working.
  </p>
@endif

@endsection
