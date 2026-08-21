@props(['activeTab' => 'dashboard'])

@php
  $tabs = [
      'dashboard' => ['label' => 'Dashboard', 'route' => 'account', 'icon' => 'fa-gauge-high'],
      'orders' => ['label' => 'Orders', 'route' => 'account.orders', 'icon' => 'fa-bag-shopping'],
      'wishlist' => ['label' => 'Wishlist', 'route' => 'account.wishlist', 'icon' => 'fa-heart'],
      'payments' => ['label' => 'Payments', 'route' => 'account.payments', 'icon' => 'fa-receipt'],
      'documents' => ['label' => 'Documents', 'route' => 'account.documents', 'icon' => 'fa-folder-open'],
      'elections' => ['label' => 'Elections', 'route' => 'account.elections', 'icon' => 'fa-check-to-slot'],
      'settings' => ['label' => 'Settings', 'route' => 'account.settings', 'icon' => 'fa-gear'],
  ];
@endphp

<nav class="account-tabs" aria-label="Account sections">
  <ul class="account-tabs__list" role="list">
    @foreach($tabs as $key => $tab)
      <li>
        <a
          href="{{ route($tab['route']) }}"
          class="account-tabs__link{{ $activeTab === $key ? ' is-active' : '' }}"
          @if($activeTab === $key) aria-current="page" @endif
        >
          <i class="fas {{ $tab['icon'] }}" aria-hidden="true"></i>
          <span>{{ $tab['label'] }}</span>
        </a>
      </li>
    @endforeach
  </ul>
</nav>
