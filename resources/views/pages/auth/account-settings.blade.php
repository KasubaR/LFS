@extends('layouts.app')

@section('content')
@php
  $categories = [
      [
          'title' => 'Personal Details',
          'description' => 'Update your name, email, and phone',
          'icon' => 'fa-user',
          'url' => route('account.settings.personal'),
      ],
      [
          'title' => 'Change Password',
          'description' => 'Update your account password',
          'icon' => 'fa-key',
          'url' => route('account.settings.password'),
      ],
  ];
@endphp

<x-account-shell
  active-tab="settings"
  title="Settings"
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  <ul class="account-settings-categories" role="list">
    @foreach($categories as $category)
      <li>
        <a href="{{ $category['url'] }}" class="account-settings-category">
          <span class="account-settings-category__icon" aria-hidden="true">
            <i class="fas {{ $category['icon'] }}"></i>
          </span>
          <span class="account-settings-category__copy">
            <span class="account-settings-category__title">{{ $category['title'] }}</span>
            <span class="account-settings-category__desc">{{ $category['description'] }}</span>
          </span>
          <span class="account-settings-category__chevron" aria-hidden="true">
            <i class="fas fa-chevron-right"></i>
          </span>
        </a>
      </li>
    @endforeach
  </ul>
</x-account-shell>
@endsection
