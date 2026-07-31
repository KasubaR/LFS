<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Membership Card</title>
<style>
  @page { margin: 14mm; }

  * { box-sizing: border-box; }

  body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 12px;
    color: #000;
    margin: 0;
  }

  .card {
    border: 1.5pt solid #111;
    padding: 18px 18px 16px;
    width: 100%;
  }

  .brand {
    width: 100%;
    margin-bottom: 16px;
  }

  .brand-logo,
  .brand-copy {
    display: inline-block;
    vertical-align: middle;
  }

  .brand-logo {
    width: 48px;
    height: 48px;
    background: #0f0f0f;
    color: #fff;
    text-align: center;
    line-height: 48px;
    font-size: 16px;
    font-weight: bold;
    letter-spacing: 1px;
    margin-right: 10px;
  }

  .eyebrow {
    font-size: 9px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: #000;
    margin: 0 0 2px;
  }

  .title {
    font-size: 22px;
    font-weight: bold;
    color: #000;
    margin: 0;
    letter-spacing: 1px;
  }

  .identity {
    margin-bottom: 14px;
  }

  .name {
    font-size: 16px;
    font-weight: bold;
    color: #000;
    margin: 0 0 3px;
  }

  .number {
    font-size: 12px;
    color: #000;
    margin: 0;
  }

  .meta {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 16px;
  }

  .meta td {
    width: 50%;
    vertical-align: top;
    padding: 0 10px 10px 0;
  }

  .meta .label {
    font-size: 8px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #000;
    margin-bottom: 2px;
  }

  .meta .value {
    font-size: 12px;
    font-weight: bold;
    color: #000;
  }

  .status {
    display: inline-block;
    border: 1pt solid #111;
    padding: 2px 8px;
    font-size: 10px;
    font-weight: bold;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: #000;
  }

  .qr-wrap {
    text-align: center;
    padding: 10px;
    border: 1pt solid #111;
  }

  .qr-wrap img {
    width: 180px;
    height: 180px;
  }

  .caption {
    text-align: center;
    margin: 10px 0 0;
    font-size: 11px;
    color: #000;
  }

  .stripe {
    height: 6px;
    margin-top: 14px;
    background: #198a4e;
  }
</style>
</head>
<body>
  <div class="card">
    <div class="brand">
      <div class="brand-logo">LFS</div>
      <div class="brand-copy">
        <p class="eyebrow">Lusaka Fitness Squad</p>
        <p class="title">Member Card</p>
      </div>
    </div>

    <div class="identity">
      <p class="name">{{ $user->name }}</p>
      <p class="number">{{ $membership->membership_number }}</p>
    </div>

    <table class="meta">
      <tr>
        <td>
          <div class="label">Status</div>
          <div class="value"><span class="status">{{ $displayStatus }}</span></div>
        </td>
        <td>
          <div class="label">Expiry</div>
          <div class="value">{{ $expiryLabel }}</div>
        </td>
      </tr>
      <tr>
        <td>
          <div class="label">Satellite</div>
          <div class="value">{{ $satelliteName }}</div>
        </td>
        <td>
          <div class="label">Plan</div>
          <div class="value">{{ $planName }}</div>
        </td>
      </tr>
    </table>

    <div class="qr-wrap">
      <img src="{{ $qrDataUri }}" alt="Membership QR code">
    </div>
    <p class="caption">Scan to verify membership</p>
    <div class="stripe"></div>
  </div>
</body>
</html>
