@extends('layouts.app')

@section('content')
<x-account-shell
  active-tab="dashboard"
  title="Membership Card"
  :user="$user"
  :membership="$membership"
  :status="$status ?? null"
>
  <div class="membership-card-page">
    @include('partials.digital-membership-card')
    <div class="membership-card-actions">
      <a href="{{ route('account') }}" class="btn btn-outline">Back to Dashboard</a>
    </div>
  </div>
</x-account-shell>
@endsection
