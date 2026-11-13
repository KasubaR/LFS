@extends('layouts.admin')

@section('content')
@php
$formatPrice = $formatPrice ?? fn ($v) => 'ZMW ' . number_format((float)$v, 2);
$id          = (int)($order['id'] ?? 0);

$statusLabels = [
    'pending_payment' => 'Pending Payment',
    'paid'            => 'Paid',
    'processing'      => 'Processing',
    'ready'           => 'Ready',
    'collected'       => 'Collected',
    'cancelled'       => 'Cancelled',
    'payment_failed'  => 'Payment Failed',
    'payment_review'  => 'Payment Needs Review',
];
$statusBadges = [
    'pending_payment' => 'orange',
    'paid'            => 'blue',
    'processing'      => 'blue',
    'ready'           => 'green',
    'collected'       => 'green',
    'cancelled'       => 'red',
    'payment_failed'  => 'red',
    'payment_review'  => 'red',
];
$allStatuses = \App\Enums\OrderStatus::ALL;

$orderBadge = $statusBadges[$order['status']] ?? 'muted';
$orderLabel = $statusLabels[$order['status']] ?? $order['status'];

$breadcrumbs = [
    ['label' => 'Admin',  'url' => '/admin/dashboard'],
    ['label' => 'Orders', 'url' => '/admin/orders'],
    ['label' => $order['orderNumber']],
];
@endphp

<!-- Page header with back link -->
<div class="admin-page-header">
  <a href="{{ url('/admin/orders') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to orders
  </a>
  <h2 class="admin-page-header__heading">
    {{ $order['orderNumber'] }}
  </h2>
</div>

<div class="order-detail">

  <!-- ── Left: order summary ─────────────────────────── -->
  <div class="order-detail__main">

    <!-- Order meta -->
    <div class="admin-card">
      <p class="admin-card__title">Order details</p>
      <table class="admin-table admin-table--compact">
        <tbody>
          <tr>
            <th>Status</th>
            <td>
              <span class="status-pill status-pill--{{ $orderBadge }}">
                {{ $orderLabel }}
              </span>
            </td>
          </tr>
          <tr>
            <th>Channel</th>
            <td>
              {{ ($order['channel'] ?? 'online') === 'offline' ? 'Offline (in person)' : 'Online' }}
              @if(($order['channel'] ?? 'online') === 'offline')
                &middot; {{ \App\Enums\OfflinePaymentMethod::LABELS[$order['paymentMethod'] ?? ''] ?? ($order['paymentMethod'] ?? '—') }}
                &middot; <a href="{{ url('/admin/orders/'.$id.'/receipt') }}">Receipt</a>
              @endif
            </td>
          </tr>
          <tr><th>Placed</th>    <td>{{ date('d M Y, H:i', strtotime($order['createdAt'])) }}</td></tr>
          <tr><th>Updated</th>   <td>{{ date('d M Y, H:i', strtotime($order['updatedAt'])) }}</td></tr>
          <tr><th>Subtotal</th>  <td>{{ ($formatPrice)($order['subtotal'] ?? 0) }}</td></tr>
          @if(($order['discount'] ?? 0) > 0)
          <tr><th>Discount{{ !empty($order['promoCode']) ? ' ('.$order['promoCode'].')' : ' (sale)' }}</th> <td>&minus; {{ ($formatPrice)($order['discount']) }}</td></tr>
          @endif
          <tr>
            <th>Total</th>
            <td><strong>{{ ($formatPrice)($order['total'] ?? 0) }}</strong></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Customer -->
    <div class="admin-card">
      <p class="admin-card__title">Customer</p>
      <table class="admin-table admin-table--compact">
        <tbody>
          <tr><th>Name</th>  <td>{{ $order['customerName'] }}</td></tr>
          @if(!empty($order['customerEmail']))
          <tr>
            <th>Email</th>
            <td>
              <a href="mailto:{{ $order['customerEmail'] }}">
                {{ $order['customerEmail'] }}
              </a>
            </td>
          </tr>
          @endif
          @if(!empty($order['customerPhone']))
          <tr>
            <th>Phone</th>
            <td>
              <a href="tel:{{ $order['customerPhone'] }}">
                {{ $order['customerPhone'] }}
              </a>
            </td>
          </tr>
          @endif
          @if(!empty($order['notes']))
          <tr><th>Notes</th> <td>{{ nl2br(htmlspecialchars($order['notes'])) }}</td></tr>
          @endif
        </tbody>
      </table>
    </div>

    <!-- Line items -->
    <div class="admin-card">
      <p class="admin-card__title">Items</p>
      @if(!empty($order['items']))
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Size</th>
                <th>Qty</th>
                <th>Unit price</th>
                <th>Line total</th>
              </tr>
            </thead>
            <tbody>
              @foreach($order['items'] as $item)
              <tr>
                <td>{{ $item['name']  ?? '' }}</td>
                <td>{{ $item['size']  ?? '—' }}</td>
                <td>{{ (int)($item['qty'] ?? 1) }}</td>
                <td>{{ ($formatPrice)($item['unitPrice'] ?? 0) }}</td>
                <td><strong>{{ ($formatPrice)($item['lineTotal'] ?? 0) }}</strong></td>
              </tr>
              @endforeach
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4"><strong>Subtotal</strong></td>
                <td>{{ ($formatPrice)($order['subtotal'] ?? 0) }}</td>
              </tr>
              @if(($order['discount'] ?? 0) > 0)
              <tr>
                <td colspan="4"><strong>Discount{{ !empty($order['promoCode']) ? ' ('.$order['promoCode'].')' : ' (sale)' }}</strong></td>
                <td>&minus; {{ ($formatPrice)($order['discount']) }}</td>
              </tr>
              @endif
              <tr>
                <td colspan="4"><strong>Total</strong></td>
                <td><strong>{{ ($formatPrice)($order['total'] ?? 0) }}</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      @else
        <p class="admin-empty__body">No line items recorded.</p>
      @endif
    </div>

  </div><!-- /.order-detail__main -->

  <!-- ── Right: sidebar ──────────────────────────────── -->
  <div class="order-detail__aside">

    @if(($order['channel'] ?? 'online') === 'offline')
    <div class="admin-card">
      <p class="admin-card__title">Void sale</p>
      @if(!empty($order['voidedAt']))
        <p>
          This sale was voided on {{ \Illuminate\Support\Carbon::parse($order['voidedAt'])->timezone('Africa/Lusaka')->format('d M Y, H:i') }}.
          The stock was put back.
        </p>
        <p><strong>Reason:</strong> {{ $order['voidReason'] }}</p>
      @elseif($order['status'] === \App\Enums\OrderStatus::Collected)
        <p class="text-muted">Use this for a sale recorded by mistake or goods that came back. The stock goes back on the shelf.</p>
        <form method="post" action="{{ url('/admin/orders/'.$id.'/void') }}"
              onsubmit="return confirm('Void this sale and put the stock back? This cannot be undone.');">
          @csrf
          <div class="admin-form__field">
            <label for="void_reason" class="admin-label">Reason</label>
            <input id="void_reason" name="void_reason" type="text" maxlength="255" required class="admin-input">
          </div>
          <button type="submit" class="admin-btn admin-btn--danger">Void sale</button>
        </form>
      @endif
    </div>
    @endif

    <!-- Update status — mirrors messages/show.php pattern -->
    <div class="admin-card">
      <p class="admin-card__title">Update status</p>
      <form method="post" action="/admin/orders/{{ $id }}/status">
        <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">
        <div class="admin-form__field">
          <select name="status" class="admin-select">
            @foreach($allStatuses as $s)
              <option value="{{ $s }}"
                {{ $order['status'] === $s ? 'selected' : '' }}>
                {{ $statusLabels[$s] ?? $s }}
              </option>
            @endforeach
          </select>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary"
                onclick="return confirm('Update order status?')">
          Save status
        </button>
      </form>
    </div>

    <!-- Payment info -->
    <div class="admin-card">
      <p class="admin-card__title">Payment</p>
      @if(!empty($payment))
        @php
        $payBadge = match($payment['status']) {
            'completed' => 'green',
            'failed'    => 'red',
            'cancelled' => 'red',
            'refunded'  => 'muted',
            default     => 'orange',
        };
        @endphp
        <table class="admin-table admin-table--compact">
          <tbody>
            <tr>
              <th>Status</th>
              <td>
                <span class="status-pill status-pill--{{ $payBadge }}">
                  {{ ucfirst($payment['status']) }}
                </span>
              </td>
            </tr>
            <tr><th>Method</th>   <td>{{ $payment['paymentMethod'] ?? '—' }}</td></tr>
            <tr><th>Amount</th>   <td>{{ ($formatPrice)($payment['amount'] ?? 0) }}</td></tr>
            @if(!empty($payment['lencoReference']))
            <tr><th>Ref</th>      <td><code>{{ $payment['lencoReference'] }}</code></td></tr>
            @endif
            @if(!empty($payment['lencoProvider']))
            <tr><th>Provider</th> <td>{{ strtoupper($payment['lencoProvider']) }}</td></tr>
            @endif
            @if(!empty($payment['lencoStatus']))
            <tr><th>Lenco</th>    <td>{{ $payment['lencoStatus'] }}</td></tr>
            @endif
            @if(!empty($payment['completedAt']))
            <tr><th>Paid at</th>  <td>{{ date('d M Y, H:i', strtotime($payment['completedAt'])) }}</td></tr>
            @endif
            @if(!empty($payment['failedAt']))
            <tr><th>Failed at</th><td>{{ date('d M Y, H:i', strtotime($payment['failedAt'])) }}</td></tr>
            @endif
            @if(!empty($payment['failureReason']))
            <tr><th>Reason</th>   <td>{{ $payment['failureReason'] }}</td></tr>
            @endif
          </tbody>
        </table>
      @else
        <p class="admin-empty__body">No payment attempt recorded yet.</p>
      @endif
    </div>

  </div><!-- /.order-detail__aside -->

</div><!-- /.order-detail -->

@endsection
