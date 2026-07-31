@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Choose Membership"
  auth-subtitle="Select your nearest satellite and membership plan to complete your application."
  :status="$status ?? null"
  :split="true">

  <form action="{{ url('/membership/apply') }}" method="post" class="space-y-4" novalidate>
    @csrf

    <div class="form-group{{ $errors->has('satellite_id') ? ' form-group--error' : '' }}">
      <label for="satellite_id">Nearest satellite</label>
      <select id="satellite_id" name="satellite_id" required>
        <option value="">Select satellite…</option>
        @foreach($satellites as $satellite)
          <option value="{{ $satellite['id'] }}" {{ (string) old('satellite_id') === (string) $satellite['id'] ? 'selected' : '' }}>
            {{ $satellite['name'] }}
          </option>
        @endforeach
      </select>
      @error('satellite_id')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('plan_id') ? ' form-group--error' : '' }}">
      <label>Membership plan</label>
      <div class="auth-plan-grid" role="radiogroup" aria-label="Membership plan">
        @foreach($plans as $plan)
          <label class="auth-plan-option">
            <input type="radio" name="plan_id" value="{{ $plan['id'] }}"
              {{ (string) old('plan_id', $plans[0]['id'] ?? '') === (string) $plan['id'] ? 'checked' : '' }} required>
            <span class="auth-plan-option__card">
              <span>
                <span class="auth-plan-option__name">{{ $plan['name'] }}</span>
                <span class="auth-plan-option__meta">{{ $plan['durationMonths'] }} months</span>
              </span>
              <span class="auth-plan-option__price">K{{ number_format($plan['price']) }}</span>
            </span>
          </label>
        @endforeach
      </div>
      @error('plan_id')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="btn btn-primary w-full justify-center mt-2">
      <i class="fas fa-id-card mr-2" aria-hidden="true"></i> Continue
    </button>
  </form>

</x-auth-form-card>
@endsection
