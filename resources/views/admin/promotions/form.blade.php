@extends('layouts.admin')

@section('content')
@php
$promotion   = $promotion   ?? [
    'name' => '', 'planId' => null, 'discountType' => 'percentage', 'discountValue' => '',
    'startsAt' => '', 'endsAt' => '', 'isActive' => true, 'notes' => '',
];
$plans       = $plans       ?? [];
$discountTypes = $discountTypes ?? ['percentage', 'fixed'];
$csrfToken   = $csrfToken   ?? '';
$isEdit      = $isEdit      ?? isset($promotion['id']);
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Promotions', 'url' => '/admin/promotions'],
    ['label' => $isEdit ? 'Edit' : 'Create'],
];
$formAction  = $isEdit ? '/admin/promotions/' . (int)$promotion['id'] . '/edit' : '/admin/promotions/create';
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/promotions') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Promotions
  </a>
  <h2 class="admin-page-header__heading">{{ $isEdit ? 'Edit Promotion' : 'Add Promotion' }}</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ $formAction }}">
    <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}">
      <label for="name" class="admin-label">Name</label>
      <input type="text" id="name" name="name" required
             value="{{ old('name', $promotion['name'] ?? '') }}"
             placeholder="e.g. Early Bird Annual"
             class="admin-input">
      @error('name')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('plan_id') ? ' form-group--error' : '' }}">
      <label for="plan_id" class="admin-label">Plan</label>
      <select id="plan_id" name="plan_id" class="admin-input">
        <option value="">All plans</option>
        @foreach($plans as $plan)
          <option value="{{ (int)$plan['id'] }}" {{ (string)old('plan_id', $promotion['planId'] ?? '') === (string)$plan['id'] ? 'selected' : '' }}>
            {{ $plan['name'] }} (K{{ number_format($plan['price'], 2) }})
          </option>
        @endforeach
      </select>
      @error('plan_id')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('discount_type') ? ' form-group--error' : '' }}">
      <label for="discount_type" class="admin-label">Discount type</label>
      <select id="discount_type" name="discount_type" class="admin-input">
        @foreach($discountTypes as $type)
          <option value="{{ $type }}" {{ old('discount_type', $promotion['discountType'] ?? 'percentage') === $type ? 'selected' : '' }}>
            {{ $type === 'percentage' ? 'Percentage off' : 'Fixed amount off' }}
          </option>
        @endforeach
      </select>
      @error('discount_type')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('discount_value') ? ' form-group--error' : '' }}">
      <label for="discount_value" class="admin-label">Discount value</label>
      <input type="number" id="discount_value" name="discount_value" min="0.01" step="0.01" required
             value="{{ old('discount_value', $promotion['discountValue'] ?? '') }}"
             class="admin-input admin-input--number">
      @error('discount_value')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('starts_at') ? ' form-group--error' : '' }}">
      <label for="starts_at" class="admin-label">Starts</label>
      <input type="date" id="starts_at" name="starts_at" required
             value="{{ old('starts_at', $promotion['startsAt'] ?? '') }}"
             class="admin-input">
      @error('starts_at')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group{{ $errors->has('ends_at') ? ' form-group--error' : '' }}">
      <label for="ends_at" class="admin-label">Ends</label>
      <input type="date" id="ends_at" name="ends_at" required
             value="{{ old('ends_at', $promotion['endsAt'] ?? '') }}"
             class="admin-input">
      @error('ends_at')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <div class="form-group">
      <label class="admin-label" style="display:flex; align-items:center; gap:0.5rem;">
        <input type="checkbox" id="is_active" name="is_active" value="1"
               {{ old('is_active', $promotion['isActive'] ?? true) ? 'checked' : '' }}>
        Active (unchecking pauses it without changing the dates)
      </label>
    </div>

    <div class="form-group{{ $errors->has('notes') ? ' form-group--error' : '' }}">
      <label for="notes" class="admin-label">Notes (optional, internal)</label>
      <textarea id="notes" name="notes" rows="3" class="admin-input">{{ old('notes', $promotion['notes'] ?? '') }}</textarea>
      @error('notes')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">{{ $isEdit ? 'Save changes' : 'Create Promotion' }}</button>
    <a href="{{ url('/admin/promotions') }}" class="admin-btn admin-btn--ghost">Cancel</a>
  </form>
</div>

@endsection
