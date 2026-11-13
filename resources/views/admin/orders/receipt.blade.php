@extends('layouts.admin')

@section('content')
@php
$money = fn ($v) => 'ZMW ' . number_format((float) $v, 2);
@endphp

<style>
  @media print {
    .admin-sidebar, .admin-topbar, .no-print { display: none !important; }
    .admin-main, .admin-content { margin: 0 !important; padding: 0 !important; }
  }
  .receipt { max-width: 28rem; }
  .receipt table { width: 100%; border-collapse: collapse; }
  .receipt th, .receipt td { padding: .25rem 0; text-align: left; vertical-align: top; }
  .receipt td:last-child, .receipt th:last-child { text-align: right; }
</style>

<div class="admin-page-header no-print">
  <a href="{{ url('/admin/orders/'.$order->id) }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to sale
  </a>
  <h2 class="admin-page-header__heading">Receipt</h2>
</div>

<div class="admin-card receipt">
  @if($order->voided_at)
    <p style="text-align:center;margin:0 0 .75rem;color:#b91c1c;"><strong>VOIDED</strong></p>
  @endif
  <p style="text-align:center;margin:0;"><strong>Lusaka Fitness Squad</strong></p>
  <p style="text-align:center;margin:0 0 1rem;">Sales receipt</p>

  <table>
    <tbody>
      <tr><th>Receipt no.</th><td>{{ $order->order_number }}</td></tr>
      <tr><th>Date</th><td>{{ $order->created_at->copy()->timezone('Africa/Lusaka')->format('d M Y, H:i') }}</td></tr>
      <tr><th>Customer</th><td>{{ $order->customer_name }}</td></tr>
      <tr><th>Phone</th><td>{{ $order->customer_phone }}</td></tr>
      <tr><th>Paid by</th><td>{{ $paymentLabel }}</td></tr>
    </tbody>
  </table>

  <hr>

  <table>
    <thead>
      <tr><th>Item</th><th>Qty</th><th>Amount</th></tr>
    </thead>
    <tbody>
      @foreach($order->items as $item)
        <tr>
          <td>{{ $item->name }} ({{ $item->size }})<br><small>{{ $money($item->unit_price) }} each</small></td>
          <td>{{ (int) $item->qty }}</td>
          <td>{{ $money($item->line_total) }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr><th colspan="2">Total</th><th>{{ $money($order->total) }}</th></tr>
    </tfoot>
  </table>

  <p style="text-align:center;margin-top:1rem;">Thank you for your purchase.</p>
</div>

<div class="no-print" style="margin-top:1rem;display:flex;gap:.5rem;">
  <button type="button" class="admin-btn admin-btn--primary" onclick="window.print()">
    <i class="fas fa-print" aria-hidden="true"></i> Print receipt
  </button>
  <a href="{{ url('/admin/orders/sales/create') }}" class="admin-btn admin-btn--ghost">Record another sale</a>
</div>
@endsection
