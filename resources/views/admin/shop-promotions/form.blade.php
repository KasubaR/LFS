@extends('layouts.admin')

@section('content')
@php
$promotion     = $promotion ?? [
    'name' => '', 'code' => '', 'discountType' => 'percentage', 'discountValue' => '', 'category' => null,
    'membersOnly' => false, 'minOrderAmount' => null, 'startsAt' => '', 'endsAt' => '', 'isActive' => true, 'notes' => '',
];
$categoryNames = $categoryNames ?? [];
$csrfToken     = $csrfToken ?? '';
$isEdit        = $isEdit ?? isset($promotion['id']);
$formAction    = $isEdit ? '/admin/shop-promotions/' . (int)$promotion['id'] . '/edit' : '/admin/shop-promotions/create';
$currentCode   = old('code', $promotion['code'] ?? '');
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/shop-promotions') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Shop promotions
  </a>
  <h2 class="admin-page-header__heading">{{ $isEdit ? 'Edit shop promotion' : 'Add shop promotion' }}</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ $formAction }}">
    <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}">
      <label for="name" class="admin-label">Name</label>
      <input type="text" id="name" name="name" required maxlength="150"
             value="{{ old('name', $promotion['name'] ?? '') }}"
             placeholder="e.g. Hoodie sale" class="admin-input">
      <p class="admin-hint">Shoppers see this name next to sale prices.</p>
      @error('name')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('code') ? ' form-group--error' : '' }}">
      <label for="code" class="admin-label">Promo code (optional)</label>
      <input type="text" id="code" name="code" maxlength="30" autocomplete="off"
             value="{{ $currentCode }}" placeholder="e.g. HOODIE10"
             class="admin-input" style="text-transform:uppercase">
      <p class="admin-hint">Leave blank for an <strong>automatic sale</strong>: the percentage comes off matching products with no code, on the shop pages and at checkout. Enter a code to make it a code customers type in. A code and a sale never stack; the customer gets whichever is better.</p>
      @error('code')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('discount_type') ? ' form-group--error' : '' }}" id="discount-type-group">
      <label for="discount_type" class="admin-label">Discount type</label>
      <select id="discount_type" name="discount_type" class="admin-input">
        @foreach($discountTypes ?? ['percentage', 'fixed'] as $type)
          <option value="{{ $type }}" {{ old('discount_type', $promotion['discountType'] ?? 'percentage') === $type ? 'selected' : '' }}>
            {{ $type === 'percentage' ? 'Percentage off' : 'Fixed amount off (Kwacha)' }}
          </option>
        @endforeach
      </select>
      <p class="admin-hint">Automatic sales are always a percentage. A fixed amount is shared across the eligible items in the cart.</p>
      @error('discount_type')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('discount_value') ? ' form-group--error' : '' }}">
      <label for="discount_value" class="admin-label">Discount value</label>
      <input type="number" id="discount_value" name="discount_value" min="0.01" step="0.01" required
             value="{{ old('discount_value', $promotion['discountValue'] ?? '') }}"
             class="admin-input admin-input--number">
      <p class="admin-hint">Percentages are capped at 99% so an order can never be free.</p>
      @error('discount_value')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('category') ? ' form-group--error' : '' }}">
      <label for="category" class="admin-label">Category</label>
      <select id="category" name="category" class="admin-input">
        <option value="">All products</option>
        @foreach($categoryNames as $slug => $label)
          <option value="{{ $slug }}" {{ (string)old('category', $promotion['category'] ?? '') === (string)$slug ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
      @error('category')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('min_order_amount') ? ' form-group--error' : '' }}">
      <label for="min_order_amount" class="admin-label">Minimum order (Kwacha, optional)</label>
      <input type="number" id="min_order_amount" name="min_order_amount" min="0" step="0.01"
             value="{{ old('min_order_amount', $promotion['minOrderAmount'] ?? '') }}"
             class="admin-input admin-input--number">
      <p class="admin-hint">Only applies to promo codes.</p>
      @error('min_order_amount')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group">
      <label class="admin-label" style="display:flex; align-items:center; gap:0.5rem;">
        <input type="checkbox" id="members_only" name="members_only" value="1"
               {{ old('members_only', $promotion['membersOnly'] ?? false) ? 'checked' : '' }}>
        Members only (customer must be logged in with an active membership)
      </label>
    </div>

    <div class="form-group{{ $errors->has('starts_at') ? ' form-group--error' : '' }}">
      <label for="starts_at" class="admin-label">Starts</label>
      <input type="date" id="starts_at" name="starts_at" required
             value="{{ old('starts_at', $promotion['startsAt'] ?? '') }}" class="admin-input">
      @error('starts_at')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('ends_at') ? ' form-group--error' : '' }}">
      <label for="ends_at" class="admin-label">Ends (last day it works)</label>
      <input type="date" id="ends_at" name="ends_at" required
             value="{{ old('ends_at', $promotion['endsAt'] ?? '') }}" class="admin-input">
      <p class="admin-hint">There is no usage limit, so a code works until this date or until you untick Active. Keep the window short.</p>
      @error('ends_at')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
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
      @error('notes')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">{{ $isEdit ? 'Save changes' : 'Create shop promotion' }}</button>
    <a href="{{ url('/admin/shop-promotions') }}" class="admin-btn admin-btn--ghost">Cancel</a>
  </form>
</div>

<script>
  // Fixed amounts only make sense for codes; hide the type picker for automatic sales.
  (function () {
    var code = document.getElementById('code');
    var group = document.getElementById('discount-type-group');
    var type = document.getElementById('discount_type');
    function sync() {
      var isCode = code.value.trim() !== '';
      group.hidden = !isCode;
      if (!isCode) type.value = 'percentage';
    }
    code.addEventListener('input', sync);
    sync();
  })();
</script>

@endsection
