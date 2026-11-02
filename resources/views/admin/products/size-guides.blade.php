@extends('layouts.admin')

@section('content')
@php
$guides      = $guides ?? [];
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Shop', 'url' => '/admin/products'],
    ['label' => 'Size guides'],
];
@endphp

<div class="admin-page-header admin-page-header--row">
  <div>
    <a href="{{ url('/admin/products') }}" class="admin-page-header__back">
      <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to products
    </a>
    <h2 class="admin-page-header__heading">Size guides</h2>
  </div>
  <a href="/admin/products/size-guides/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> New size guide
  </a>
</div>

@if(empty($guides))
  <div class="admin-empty">
    <i class="fas fa-ruler-horizontal admin-empty__icon" aria-hidden="true"></i>
    <p class="admin-empty__title">No size guides yet</p>
    <p class="admin-empty__body">Create one, then choose it on a product to show it on that product's page.</p>
  </div>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Columns</th>
          <th>Sizes</th>
          <th>Products</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($guides as $g)
        <tr>
          <td><strong>{{ $g['name'] }}</strong></td>
          <td>{{ implode(', ', $g['columns']) }}</td>
          <td>{{ $g['rowCount'] }}</td>
          <td>{{ $g['productCount'] }}</td>
          <td class="row-menu-cell">
            <details class="row-menu">
              <summary class="admin-btn admin-btn--ghost admin-btn--sm row-menu__toggle" aria-label="Actions for {{ $g['name'] }}">
                <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>
              </summary>
              <div class="row-menu__list" role="menu">
                <a href="/admin/products/size-guides/{{ $g['id'] }}/edit" role="menuitem">
                  <i class="fas fa-pen" aria-hidden="true"></i> Edit
                </a>
                <form method="post" action="/admin/products/size-guides/{{ $g['id'] }}/delete"
                      onsubmit="return confirm('Delete this size guide?');">
                  @csrf
                  <button type="submit" class="row-menu__danger" role="menuitem"
                          {{ $g['productCount'] > 0 ? 'disabled' : '' }}
                          title="{{ $g['productCount'] > 0 ? 'In use by products — can\'t delete' : 'Delete' }}">
                    <i class="fas fa-trash" aria-hidden="true"></i> Delete
                  </button>
                </form>
              </div>
            </details>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <p class="admin-help-text" style="margin-top:0.75rem;">
    A guide that products use can't be deleted — pick a different guide on those products first.
    Choose a product's guide on its edit page.
  </p>
@endif

@include('admin.partials.row-menu-assets')

@endsection
