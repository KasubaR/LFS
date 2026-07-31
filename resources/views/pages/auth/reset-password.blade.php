@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Reset Password"
  auth-subtitle="Choose a new password for your account."
  :status="$status ?? null">

  <form action="{{ url('/reset-password') }}" method="post" class="space-y-4" novalidate>
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <div class="form-group{{ $errors->has('email') ? ' form-group--error' : '' }}">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email" readonly>
      @error('email')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
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
      <i class="fas fa-key mr-2" aria-hidden="true"></i> Reset Password
    </button>
  </form>

  <p class="auth-links">
    <a href="{{ url('/login') }}">Back to sign in</a>
  </p>

</x-auth-form-card>
@endsection
