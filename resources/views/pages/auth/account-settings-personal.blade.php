@extends('layouts.app')

@section('content')
<x-account-shell
  active-tab="settings"
  title="Personal Details"
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  <p class="account-backlink">
    <a href="{{ route('account.settings') }}">
      <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to settings
    </a>
  </p>

  <div class="dashboard-card">
    <h2 class="dashboard-card__title">Personal Details</h2>
    <p class="dashboard-empty account-settings-intro">Update your name, email, and phone.</p>

    <form action="{{ route('account.settings.personal.update') }}" method="post" class="account-settings-form lfs-form" enctype="multipart/form-data" novalidate>
      @csrf

      @php
        $avatarUrl = $user->avatarUrl();
        $initials = $user->initials();
      @endphp

      <div class="account-avatar-field{{ $errors->has('avatar') ? ' form-group--error' : '' }}">
        <div class="account-avatar-field__preview" aria-hidden="true">
          @if($avatarUrl)
            <img src="{{ $avatarUrl }}" alt="" class="account-avatar-field__img" width="96" height="96">
          @else
            <span class="account-avatar-field__initials">{{ $initials }}</span>
          @endif
        </div>

        <div class="account-avatar-field__controls">
          <label for="settings-avatar" class="account-avatar-field__label">Profile photo</label>
          <p class="form-group__hint">JPG, PNG, or WebP. Max 2 MB.</p>
          <input type="file" id="settings-avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
          @error('avatar')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror

          @if($avatarUrl)
            <label class="account-avatar-field__remove">
              <input type="checkbox" name="remove_avatar" value="1">
              Remove current photo
            </label>
          @endif
        </div>
      </div>

      <div class="form-group">
        <label for="settings-email">Email</label>
        <input type="email" id="settings-email" value="{{ $user->email }}" disabled readonly>
        <p class="form-group__hint">Email cannot be changed here. Contact LFS if you need to update it.</p>
      </div>

      <div class="form-group{{ $errors->has('last_name') ? ' form-group--error' : '' }}">
        <label for="settings-last-name">Last name</label>
        <input type="text" id="settings-last-name" name="last_name" value="{{ old('last_name', $user->last_name) }}" required autocomplete="family-name">
        @error('last_name')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
      </div>

      <div class="form-group{{ $errors->has('other_names') ? ' form-group--error' : '' }}">
        <label for="settings-other-names">Other name(s)</label>
        <input type="text" id="settings-other-names" name="other_names" value="{{ old('other_names', $user->other_names) }}" required autocomplete="given-name">
        @error('other_names')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
      </div>

      <div class="form-group{{ $errors->has('phone') ? ' form-group--error' : '' }}">
        <label for="settings-phone">Phone</label>
        <input type="tel" id="settings-phone" name="phone" value="{{ old('phone', $user->phone) }}" required autocomplete="tel">
        @error('phone')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
      </div>

      <div class="account-settings-form__grid">
        <div class="form-group{{ $errors->has('gender') ? ' form-group--error' : '' }}">
          <label for="settings-gender">Sex</label>
          <select id="settings-gender" name="gender" required>
            @foreach($genderOptions as $value => $label)
              <option value="{{ $value }}" {{ (string) old('gender', $user->gender) === (string) $value ? 'selected' : '' }}>
                {{ $label }}
              </option>
            @endforeach
          </select>
          @error('gender')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="form-group{{ $errors->has('t_shirt_size') ? ' form-group--error' : '' }}">
          <label for="settings-tshirt">T-shirt size</label>
          <select id="settings-tshirt" name="t_shirt_size" required>
            @foreach($tShirtSizes as $value => $label)
              <option value="{{ $value }}" {{ (string) old('t_shirt_size', $user->t_shirt_size) === (string) $value ? 'selected' : '' }}>
                {{ $label }}
              </option>
            @endforeach
          </select>
          @error('t_shirt_size')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
        </div>
      </div>

      <div class="form-group{{ $errors->has('nationality') ? ' form-group--error' : '' }}">
        <label for="settings-nationality">Nationality</label>
        <input type="text" id="settings-nationality" name="nationality" value="{{ old('nationality', $user->nationality) }}" required autocomplete="country-name">
        @error('nationality')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
      </div>

      <div class="account-settings-form__grid">
        <div class="form-group{{ $errors->has('satellite_id') ? ' form-group--error' : '' }}">
          <label for="settings-satellite">Satellite</label>
          <select id="settings-satellite" name="satellite_id" required>
            <option value="">Select satellite…</option>
            @foreach($satellites as $satellite)
              <option value="{{ $satellite['id'] }}" {{ (string) old('satellite_id', $user->satellite_id) === (string) $satellite['id'] ? 'selected' : '' }}>
                {{ $satellite['name'] }}
              </option>
            @endforeach
          </select>
          @error('satellite_id')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="form-group{{ $errors->has('town') ? ' form-group--error' : '' }}">
          <label for="settings-town">Town</label>
          <input type="text" id="settings-town" name="town" value="{{ old('town', $user->town) }}" required autocomplete="address-level2">
          @error('town')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
        </div>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="fas fa-floppy-disk mr-2" aria-hidden="true"></i> Save changes
      </button>
    </form>
  </div>
</x-account-shell>
@endsection
