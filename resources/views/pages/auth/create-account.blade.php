@extends('layouts.app')

@section('content')
<x-auth-form-card
  auth-title="Create Account"
  auth-subtitle="Join Lusaka Fitness Squad. Create your account, then verify your email to choose a membership plan."
  :status="$status ?? null">

  <form action="{{ url('/create-account') }}" method="post" class="space-y-4" novalidate>
    @csrf

    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}">
      <label for="name">Full name</label>
      <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
      @error('name')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('email') ? ' form-group--error' : '' }}">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
      @error('email')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group auth-form__password-wrap{{ $errors->has('password') ? ' form-group--error' : '' }}">
      <label for="password">Password</label>
      <div class="auth-form__input-wrap">
        <input type="password" id="password" name="password" required autocomplete="new-password">
        <button type="button" class="auth-form__eye" data-toggle-password="password" aria-label="Show password">
          <i class="fas fa-eye-slash" aria-hidden="true"></i>
        </button>
      </div>
      @error('password')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group auth-form__password-wrap{{ $errors->has('password_confirmation') ? ' form-group--error' : '' }}">
      <label for="password_confirmation">Confirm password</label>
      <div class="auth-form__input-wrap">
        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        <button type="button" class="auth-form__eye" data-toggle-password="password_confirmation" aria-label="Show password">
          <i class="fas fa-eye-slash" aria-hidden="true"></i>
        </button>
      </div>
      @error('password_confirmation')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('phone') ? ' form-group--error' : '' }}">
      <label for="phone">Phone</label>
      <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel">
      @error('phone')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('gender') ? ' form-group--error' : '' }}">
      <label for="gender">Sex</label>
      <select id="gender" name="gender" required>
        <option value="">Select…</option>
        <option value="male" {{ old('gender') === 'male' ? 'selected' : '' }}>Male</option>
        <option value="female" {{ old('gender') === 'female' ? 'selected' : '' }}>Female</option>
        <option value="other" {{ old('gender') === 'other' ? 'selected' : '' }}>Other</option>
        <option value="prefer_not_to_say" {{ old('gender') === 'prefer_not_to_say' ? 'selected' : '' }}>Prefer not to say</option>
      </select>
      @error('gender')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('nationality') ? ' form-group--error' : '' }}">
      <label for="nationality">Nationality</label>
      <input type="text" id="nationality" name="nationality" value="{{ old('nationality') }}" required>
      @error('nationality')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('t_shirt_size') ? ' form-group--error' : '' }}">
      <label for="t_shirt_size">T-shirt size</label>
      <select id="t_shirt_size" name="t_shirt_size" required>
        <option value="">Select size…</option>
        @foreach(\App\Enums\TShirtSize::ALL as $size)
          <option value="{{ $size }}" {{ old('t_shirt_size') === $size ? 'selected' : '' }}>{{ $size }}</option>
        @endforeach
      </select>
      @error('t_shirt_size')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('town') ? ' form-group--error' : '' }}">
      <label for="town">Town</label>
      <input type="text" id="town" name="town" value="{{ old('town') }}" required>
      @error('town')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="btn btn-primary w-full justify-center mt-2">
      <i class="fas fa-user-plus mr-2" aria-hidden="true"></i> Create Account
    </button>
  </form>

  <p class="auth-links">
    Already have an account? <a href="{{ url('/login') }}">Sign in</a>
  </p>

</x-auth-form-card>
@endsection
