@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Change Password"
  auth-subtitle="Please set a new password to continue using your account."
  :status="$status ?? null"
  :split="true">

  <form action="{{ url('/password/change') }}" method="post" class="space-y-4" novalidate>
    @csrf

    <div class="form-group auth-form__password-wrap{{ $errors->has('current_password') ? ' form-group--error' : '' }}">
      <label for="current_password">Current (temporary) password</label>
      <div class="auth-form__input-wrap">
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password" autofocus>
        <button type="button" class="auth-form__eye" data-toggle-password="current_password" aria-label="Show password">
          <i class="fas fa-eye-slash" aria-hidden="true"></i>
        </button>
      </div>
      @error('current_password')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group auth-form__password-wrap{{ $errors->has('password') ? ' form-group--error' : '' }}">
      <label for="password">New password</label>
      <div class="auth-form__input-wrap">
        <input type="password" id="password" name="password" required autocomplete="new-password">
        <button type="button" class="auth-form__eye" data-toggle-password="password" aria-label="Show password">
          <i class="fas fa-eye-slash" aria-hidden="true"></i>
        </button>
      </div>
      @error('password')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group auth-form__password-wrap{{ $errors->has('password_confirmation') ? ' form-group--error' : '' }}">
      <label for="password_confirmation">Confirm new password</label>
      <div class="auth-form__input-wrap">
        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        <button type="button" class="auth-form__eye" data-toggle-password="password_confirmation" aria-label="Show password">
          <i class="fas fa-eye-slash" aria-hidden="true"></i>
        </button>
      </div>
      @error('password_confirmation')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="btn btn-primary w-full justify-center mt-2">
      <i class="fas fa-key mr-2" aria-hidden="true"></i> Update Password
    </button>
  </form>

</x-auth-form-card>
@endsection
