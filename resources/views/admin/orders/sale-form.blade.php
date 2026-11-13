@extends('layouts.admin')

@section('content')
@php
$products       = $products ?? [];
$paymentMethods = $paymentMethods ?? [];
$threshold      = (int) ($onlineSelloutThreshold ?? 20);

// Re-draw the lines the user had if validation failed; otherwise start with one empty line.
$oldItems = old('items');
$initialLines = is_array($oldItems) && count($oldItems) > 0
    ? array_values(array_map(fn ($i) => [
        'product_id' => (string) ($i['product_id'] ?? ''),
        'size'       => (string) ($i['size'] ?? ''),
        'qty'        => (string) ($i['qty'] ?? '1'),
        'unit_price' => (string) ($i['unit_price'] ?? ''),
    ], $oldItems))
    : [['product_id' => '', 'size' => '', 'qty' => '1', 'unit_price' => '']];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/orders') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to orders
  </a>
  <h2 class="admin-page-header__heading">Record offline sale</h2>
</div>

<p class="admin-empty" style="text-align:left;background:none;padding:0 0 1rem;">
  Use this for sales made in person. The stock is taken straight away, including the units the website keeps
  in reserve (online sales stop when a size has {{ $threshold }} or fewer left).
</p>

<div class="admin-form-card">
  <form method="post" action="{{ url('/admin/orders/sales') }}" id="saleForm">
    @csrf

    @error('items')
      <p class="form-group__error" role="alert" style="margin-bottom:1rem;">{{ $message }}</p>
    @enderror

    <div class="form-group">
      <span class="admin-label">Items</span>
      <div id="saleLines"></div>
      <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="addLine">
        <i class="fas fa-plus" aria-hidden="true"></i> Add another item
      </button>
      <p style="margin-top:.75rem;"><strong>Total: <span id="saleTotal">ZMW 0.00</span></strong></p>
    </div>

    <div class="form-group{{ $errors->has('customer_name') ? ' form-group--error' : '' }}">
      <label for="customer_name" class="admin-label">Customer name</label>
      <input type="text" id="customer_name" name="customer_name" required maxlength="255"
             value="{{ old('customer_name') }}" class="admin-input">
      @error('customer_name')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('customer_phone') ? ' form-group--error' : '' }}">
      <label for="customer_phone" class="admin-label">Customer phone</label>
      <input type="tel" id="customer_phone" name="customer_phone" required maxlength="30"
             value="{{ old('customer_phone') }}" class="admin-input" autocomplete="off">
      @error('customer_phone')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('customer_email') ? ' form-group--error' : '' }}">
      <label for="customer_email" class="admin-label">Customer email <span class="text-muted">(optional)</span></label>
      <input type="email" id="customer_email" name="customer_email" maxlength="254"
             value="{{ old('customer_email') }}" class="admin-input" autocomplete="off">
      @error('customer_email')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('payment_method') ? ' form-group--error' : '' }}">
      <label for="payment_method" class="admin-label">Payment method</label>
      <select id="payment_method" name="payment_method" required class="admin-select">
        @foreach($paymentMethods as $value => $label)
          <option value="{{ $value }}" {{ old('payment_method', 'cash') === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
      @error('payment_method')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('notes') ? ' form-group--error' : '' }}">
      <label for="notes" class="admin-label">Notes <span class="text-muted">(optional)</span></label>
      <textarea id="notes" name="notes" rows="2" maxlength="1000" class="admin-input">{{ old('notes') }}</textarea>
      @error('notes')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">
      <i class="fas fa-receipt" aria-hidden="true"></i> Record sale
    </button>
  </form>
</div>

<script>
(function () {
  var products = @json($products);
  var lines = @json($initialLines);
  var box = document.getElementById('saleLines');
  var totalEl = document.getElementById('saleTotal');

  function productById(id) {
    for (var i = 0; i < products.length; i++) if (products[i].id === id) return products[i];
    return null;
  }

  function money(n) { return 'ZMW ' + (Math.round(n * 100) / 100).toFixed(2); }

  function updateTotal() {
    var total = 0;
    box.querySelectorAll('[data-line]').forEach(function (row) {
      var p = productById(row.querySelector('[data-f=product]').value);
      var qty = parseInt(row.querySelector('[data-f=qty]').value, 10) || 0;
      var priceVal = row.querySelector('[data-f=price]').value;
      var price = priceVal !== '' ? parseFloat(priceVal) : (p ? p.price : 0);
      total += (isNaN(price) ? 0 : price) * qty;
    });
    totalEl.textContent = money(total);
  }

  function fillSizes(row, selected) {
    var p = productById(row.querySelector('[data-f=product]').value);
    var sel = row.querySelector('[data-f=size]');
    sel.innerHTML = '';
    var blank = document.createElement('option');
    blank.value = ''; blank.textContent = p ? 'Choose size' : 'Choose a product first';
    sel.appendChild(blank);
    (p ? p.sizes : []).forEach(function (s) {
      var o = document.createElement('option');
      o.value = s.size;
      o.textContent = s.size + ' (' + s.stock + ' in stock)';
      if (s.size === selected) o.selected = true;
      sel.appendChild(o);
    });
  }

  function render() {
    box.innerHTML = '';
    lines.forEach(function (line, idx) {
      var row = document.createElement('div');
      row.setAttribute('data-line', '');
      row.style.cssText = 'display:grid;grid-template-columns:2fr 1.2fr .6fr 1fr auto;gap:.5rem;margin-bottom:.5rem;align-items:center;';

      var productSel = document.createElement('select');
      productSel.className = 'admin-select'; productSel.required = true;
      productSel.name = 'items[' + idx + '][product_id]'; productSel.setAttribute('data-f', 'product');
      productSel.innerHTML = '<option value="">Choose product</option>';
      products.forEach(function (p) {
        var o = document.createElement('option');
        o.value = p.id; o.textContent = p.name + ' — ' + money(p.price);
        if (p.id === line.product_id) o.selected = true;
        productSel.appendChild(o);
      });

      var sizeSel = document.createElement('select');
      sizeSel.className = 'admin-select'; sizeSel.required = true;
      sizeSel.name = 'items[' + idx + '][size]'; sizeSel.setAttribute('data-f', 'size');

      var qty = document.createElement('input');
      qty.type = 'number'; qty.min = '1'; qty.max = '1000'; qty.required = true;
      qty.className = 'admin-input'; qty.value = line.qty || '1';
      qty.name = 'items[' + idx + '][qty]'; qty.setAttribute('data-f', 'qty'); qty.setAttribute('aria-label', 'Quantity');

      var price = document.createElement('input');
      price.type = 'number'; price.min = '0'; price.step = '0.01';
      price.className = 'admin-input'; price.value = line.unit_price || '';
      price.placeholder = 'Unit price';
      price.name = 'items[' + idx + '][unit_price]'; price.setAttribute('data-f', 'price'); price.setAttribute('aria-label', 'Unit price (leave blank for the normal price)');

      var remove = document.createElement('button');
      remove.type = 'button'; remove.className = 'admin-btn admin-btn--danger admin-btn--sm';
      remove.textContent = 'Remove'; remove.disabled = lines.length === 1;
      remove.addEventListener('click', function () { sync(); lines.splice(idx, 1); render(); });

      [productSel, sizeSel, qty, price, remove].forEach(function (el) { row.appendChild(el); });
      box.appendChild(row);

      fillSizes(row, line.size);
      productSel.addEventListener('change', function () {
        fillSizes(row, '');
        var p = productById(productSel.value);
        price.placeholder = p ? 'Normal: ' + p.price.toFixed(2) : 'Unit price';
        updateTotal();
      });
      var p0 = productById(line.product_id);
      if (p0) price.placeholder = 'Normal: ' + p0.price.toFixed(2);
      [qty, price].forEach(function (el) { el.addEventListener('input', updateTotal); });
    });
    updateTotal();
  }

  // Keep what has been typed when a line is added or removed.
  function sync() {
    lines = Array.prototype.map.call(box.querySelectorAll('[data-line]'), function (row) {
      return {
        product_id: row.querySelector('[data-f=product]').value,
        size: row.querySelector('[data-f=size]').value,
        qty: row.querySelector('[data-f=qty]').value,
        unit_price: row.querySelector('[data-f=price]').value
      };
    });
  }

  document.getElementById('addLine').addEventListener('click', function () {
    sync();
    lines.push({ product_id: '', size: '', qty: '1', unit_price: '' });
    render();
  });

  render();
})();
</script>
@endsection
