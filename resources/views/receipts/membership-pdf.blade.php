<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Receipt {{ $payment->receipt_number ?? $payment->id }}</title>
<style>
  @page { margin: 12mm; }

  * { box-sizing: border-box; }

  body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 11px;
    color: #1a1a1a;
    margin: 0;
  }

  h1 {
    font-size: 22px;
    color: #1e3a2a;
    margin: 0 0 4px;
    letter-spacing: 1px;
  }

  .header {
    display: table;
    width: 100%;
    border-bottom: 2pt solid #1e3a2a;
    padding-bottom: 10px;
    margin-bottom: 16px;
  }

  .header .issuer,
  .header .meta-box {
    display: table-cell;
    vertical-align: top;
  }

  .header .issuer { width: 60%; }
  .header .meta-box { width: 40%; text-align: right; }

  .issuer .org-name {
    font-size: 15px;
    font-weight: bold;
    color: #1e3a2a;
    margin-bottom: 2px;
  }

  .issuer .org-line { color: #444; line-height: 1.5; }

  .meta-box-inner {
    display: inline-block;
    text-align: left;
    border-left: 3pt solid #e07b39;
    background: #f4f6fb;
    padding: 8px 12px;
    margin-top: 4px;
  }

  .meta-box-inner .row { line-height: 1.6; }
  .meta-box-inner .label { color: #666; }
  .meta-box-inner .value { font-weight: bold; color: #1a1a1a; }

  .section-title {
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #1e3a2a;
    margin: 18px 0 6px;
  }

  .to-box { line-height: 1.6; }
  .to-box .name { font-weight: bold; font-size: 12px; }

  table.items {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin-top: 6px;
  }

  table.items th {
    background: #1e3a2a;
    color: #fff;
    text-align: left;
    padding: 7px 8px;
    font-size: 10px;
    text-transform: uppercase;
  }

  table.items td {
    padding: 8px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
  }

  table.totals {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
  }

  table.totals td {
    padding: 5px 8px;
  }

  table.totals .label { text-align: right; color: #555; }
  table.totals .value { text-align: right; width: 120px; }

  table.totals .grand td {
    font-weight: bold;
    font-size: 13px;
    color: #1e3a2a;
    border-top: 1.5pt solid #1e3a2a;
    border-bottom: 1.5pt solid #1e3a2a;
    padding: 8px;
  }

  .payment-box {
    margin-top: 16px;
    border: 1pt solid #e07b39;
    background: #fff8ec;
    padding: 10px 12px;
  }

  .payment-box .row { line-height: 1.7; }
  .payment-box .label { color: #666; display: inline-block; width: 130px; }
  .payment-box .value { font-weight: bold; color: #1a1a1a; }

  .footer {
    margin-top: 26px;
    text-align: center;
    color: #888;
    font-size: 8px;
    border-top: 1px solid #e5e7eb;
    padding-top: 8px;
  }
</style>
</head>
<body>

  <div class="header">
    <div class="issuer">
      <div class="org-name">{{ $issuer['name'] ?? 'Lusaka Fitness Squad' }}</div>
      <div class="org-line">{{ $issuer['address'] ?? '' }}</div>
      <div class="org-line">{{ $issuer['phone'] ?? '' }} &nbsp;·&nbsp; {{ $issuer['email'] ?? '' }}</div>
    </div>
    <div class="meta-box">
      <h1>RECEIPT</h1>
      <div class="meta-box-inner">
        <div class="row"><span class="label">Receipt No: </span><span class="value">{{ $payment->receipt_number ?? ('LFS-RCT-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)) }}</span></div>
        <div class="row"><span class="label">Date Paid: </span><span class="value">{{ ($payment->paid_at ?? $payment->created_at)->format('j M Y') }}</span></div>
      </div>
    </div>
  </div>

  <div class="section-title">Received From</div>
  <div class="to-box">
    <div class="name">{{ $user->name }}</div>
    <div>{{ $user->email }}</div>
    @if($user->phone)
      <div>{{ $user->phone }}</div>
    @endif
    @if($membership?->membership_number)
      <div>Membership No: {{ $membership->membership_number }}</div>
    @endif
  </div>

  <div class="section-title">Payment Details</div>
  <table class="items">
    <colgroup>
      <col style="width:55%">
      <col style="width:25%">
      <col style="width:20%">
    </colgroup>
    <thead>
      <tr>
        <th>Description</th>
        <th>Period Covered</th>
        <th>Amount</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          LFS Annual Membership
          @if($payment->status === 'partially_paid')
            <br><span style="color:#b45309;">(partial payment)</span>
          @endif
        </td>
        <td>
          @if($payment->covers_from && $payment->covers_to)
            {{ $payment->covers_from->format('j M Y') }} – {{ $payment->covers_to->format('j M Y') }}
          @else
            —
          @endif
        </td>
        <td>{{ $payment->currency ?? 'ZMW' }} {{ number_format((float) $payment->amount, 2) }}</td>
      </tr>
    </tbody>
  </table>

  <table class="totals">
    <tr>
      <td class="label">Amount Paid</td>
      <td class="value">{{ $payment->currency ?? 'ZMW' }} {{ number_format((float) $payment->amount_paid, 2) }}</td>
    </tr>
    <tr class="grand">
      <td class="label">Total Received</td>
      <td class="value">{{ $payment->currency ?? 'ZMW' }} {{ number_format((float) $payment->amount_paid, 2) }}</td>
    </tr>
  </table>

  <div class="payment-box">
    <div class="row"><span class="label">Payment method:</span> <span class="value">{{ ucfirst(str_replace('_', ' ', $payment->payment_gateway ?? 'Mobile Money')) }}{{ $payment->lenco_provider ? ' ('.strtoupper($payment->lenco_provider).')' : '' }}</span></div>
    <div class="row"><span class="label">Reference:</span> <span class="value">{{ $payment->payment_reference ?? '—' }}</span></div>
    <div class="row"><span class="label">Status:</span> <span class="value">{{ ucfirst(str_replace('_', ' ', $payment->status)) }}</span></div>
  </div>

  <div class="footer">
    This receipt confirms payment received by {{ $issuer['name'] ?? 'Lusaka Fitness Squad' }}. Thank you for being part of the squad.<br>
    Generated {{ now()->format('j M Y, g:i A') }} &middot; {{ $issuer['email'] ?? '' }}
  </div>

</body>
</html>
