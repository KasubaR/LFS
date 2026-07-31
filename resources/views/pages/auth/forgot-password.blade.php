@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Forgot Password"
  auth-subtitle="Enter your email and we'll send you a password reset link."
  :status="$status ?? null">

  <form action="{{ url('/forgot-password') }}" method="post" class="space-y-4" novalidate>
    @csrf

    <div class="form-group{{ $errors->has('email') ? ' form-group--error' : '' }}">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
      @error('email')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="btn btn-primary w-full justify-center mt-2">
      <i class="fas fa-envelope mr-2" aria-hidden="true"></i> Send Reset Link
    </button>
  </form>

  <p class="auth-links">
    <a href="{{ url('/login') }}">Back to sign in</a>
  </p>

</x-auth-form-card>
@endsection
