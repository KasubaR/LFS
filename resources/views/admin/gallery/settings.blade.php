@extends('layouts.admin')

@section('content')
@php
@if(!empty($error ?? null))
  <div class="gallery-error-banner" role="alert">
    <i class="fas fa-triangle-exclamation"></i>
    <span>{{ $error }}</span>
  </div>
@endif

<div class="admin-panel">
  <div class="admin-panel__header">
    <h1 class="admin-panel__title">Gallery Settings</h1>
  </div>
  <div class="admin-panel__body">
  <form method="POST" action="/admin/gallery/settings" enctype="multipart/form-data" class="admin-form">
    <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">

    <div class="admin-form__field">
      <label class="admin-label" for="bannerImageFile">Gallery Banner Image</label>

      @if(!empty($bannerImage))
        <div style="margin-bottom:0.75rem;">
          <p style="font-size:0.8rem;color:var(--text-dim);margin-bottom:0.4rem;">Current banner:</p>
          <img src="{{ $bannerImage }}"
               alt="Current gallery banner"
               style="max-width:100%;max-height:180px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border-color);">
          <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.6rem;font-size:0.85rem;color:var(--flag-red);cursor:pointer;">
            <input type="checkbox" name="removeBanner" value="1">
            Remove current banner
          </label>
        </div>
      @endif

      <input type="file"
             id="bannerImageFile"
             name="bannerImageFile"
             accept="image/jpeg,image/png,image/webp"
             class="admin-input">
      <p style="font-size:0.78rem;color:var(--text-dim);margin-top:0.35rem;">Upload an image or paste a URL below. Leave both empty to remove the banner.</p>
    </div>

    <div class="admin-form__field">
      <label class="admin-label" for="bannerImageUrl">Or Banner Image URL</label>
      <input type="text"
             id="bannerImageUrl"
             name="bannerImageUrl"
             class="admin-input"
             value="{{ $bannerImage ?? '' }}"
             placeholder="/images/gallery/… or https://…">
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="admin-btn admin-btn--primary">
        <i class="fas fa-save"></i> Save Settings
      </button>
      <a href="{{ url('/admin/gallery') }}" class="admin-btn admin-btn--ghost">
        Cancel
      </a>
    </div>
  </form>
  </div>
</div>

@endsection
