@extends('layouts.admin')

@section('content')
@php $csrfToken = $csrfToken ?? ''; @endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/promotions') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Promotions
  </a>
  <h2 class="admin-page-header__heading">Next-year registration</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="/admin/promotions/membership-year">
    <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

    <p>
      From the date below until 31 December, new sign-ups and renewals pay for the
      <strong>{{ $targetYear }}</strong> membership year. They get the full annual fee, start on
      1 January {{ $targetYear }} and expire on 31 December {{ $targetYear }}. Members who are active
      for this year can renew early. Leave empty to keep the default: renewals open on 1 January.
    </p>

    <p>
      Status:
      @if($isOpenNow)
        <strong>Open now</strong> for {{ $targetYear }}.
      @elseif($opensOn)
        Opens on <strong>{{ $opensOn }}</strong>.
      @else
        Not scheduled.
      @endif
    </p>

    <div class="form-group{{ $errors->has('next_year_opens_on') ? ' form-group--error' : '' }}">
      <label for="next_year_opens_on" class="admin-label">Opens on</label>
      <input type="date" id="next_year_opens_on" name="next_year_opens_on" class="admin-input"
             value="{{ old('next_year_opens_on', $opensOn) }}">
      @error('next_year_opens_on')
      <p class="form-group__error" role="alert">{{ $message }}</p>
      @enderror
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">Save</button>
  </form>
</div>
@endsection
