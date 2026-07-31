@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Verify Email"
  auth-subtitle="Thanks for signing up! Please verify your email address before continuing."
  :status="$status ?? null"
  :split="true">

  <div class="auth-verify">
    <p class="auth-verify__copy">
      We sent a verification link to your email address. Tap the link in that email to verify your account
      and continue — you will be signed in automatically.
    </p>

    <form action="{{ url('/email/verification-notification') }}" method="post" class="auth-verify__form">
      @csrf
      <button type="submit" class="btn btn-primary auth-verify__submit">
        <i class="fas fa-paper-plane" aria-hidden="true"></i>
        <span>Resend Verification Email</span>
      </button>
    </form>

    <div class="auth-links">
      <form action="{{ url('/logout') }}" method="post" class="auth-logout-form">
        @csrf
        <button type="submit" class="auth-logout-form__btn">
          Sign out
        </button>
      </form>
    </div>
  </div>

</x-auth-form-card>
@endsection
