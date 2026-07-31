@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Sign In"
  auth-subtitle="Access your LFS member account."
  :status="$status ?? null"
  :split="true">

  <form action="{{ url('/login') }}" method="post" class="space-y-4" novalidate>
    @csrf

    <div class="form-group{{ $errors->has('email') ? ' form-group--error' : '' }}">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
      @error('email')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group auth-form__password-wrap{{ $errors->has('password') ? ' form-group--error' : '' }}">
      <label for="password">Password</label>
      <div class="auth-form__input-wrap">
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <button type="button" class="auth-form__eye" data-toggle-password="password" aria-label="Show password">
          <i class="fas fa-eye-slash" aria-hidden="true"></i>
        </button>
      </div>
      @error('password')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group">
      <label class="auth-form__remember">
        <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
        <span>Remember me</span>
      </label>
    </div>

    <button type="submit" class="btn btn-primary w-full justify-center mt-2">
      <i class="fas fa-right-to-bracket mr-2" aria-hidden="true"></i> Sign In
    </button>
  </form>

  <p class="auth-links">
    <a href="{{ url('/forgot-password') }}">Forgot your password?</a><br>
    Don't have an account? <a href="{{ url('/create-account') }}">Create one</a>
  </p>

</x-auth-form-card>
@endsection
