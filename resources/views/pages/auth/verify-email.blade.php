@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Verify Email"
  auth-subtitle="Thanks for signing up! Please verify your email address before continuing."
  :status="$status ?? null"
  :split="true">

  <p style="color:rgba(15,15,15,0.7); line-height:1.7; margin-bottom:1.25rem;">
    We sent a verification link to your email address. Click the link in that email to verify your account.
    Once verified, you will choose your satellite and membership plan.
  </p>

  <form action="{{ url('/email/verification-notification') }}" method="post">
    @csrf
    <button type="submit" class="btn btn-primary w-full justify-center">
      <i class="fas fa-paper-plane mr-2" aria-hidden="true"></i> Resend Verification Email
    </button>
  </form>

  <p class="auth-links">
    <form action="{{ url('/logout') }}" method="post" style="display:inline;">
      @csrf
      <button type="submit" style="background:none;border:none;padding:0;color:var(--green);font:inherit;cursor:pointer;font-weight:500;">
        Sign out
      </button>
    </form>
  </p>

</x-auth-form-card>
@endsection
