@extends('layouts.admin')

@section('content')
@php
$isEdit      = !empty($guide['id']);
$columns     = old('columns', $guide['columns']);
$rows        = old('rows', $guide['rows']);
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Shop', 'url' => '/admin/products'],
    ['label' => 'Size guides', 'url' => '/admin/products/size-guides'],
    ['label' => $isEdit ? 'Edit' : 'New'],
];
$action = $isEdit ? '/admin/products/size-guides/'.(int) $guide['id'].'/edit' : '/admin/products/size-guides';
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/products/size-guides') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to size guides
  </a>
  <h2 class="admin-page-header__heading">{{ $isEdit ? 'Edit size guide' : 'New size guide' }}</h2>
</div>

<div class="admin-form-card">
  <form method="post" action="{{ url($action) }}" id="sg-form">
    @csrf

    <div class="form-group{{ $errors->has('name') ? ' form-group--error' : '' }}">
      <label for="name" class="admin-label">Name</label>
      <input type="text" id="name" name="name" required maxlength="100"
             value="{{ old('name', $guide['name']) }}" placeholder="e.g. Standard apparel, Shorts" class="admin-input">
      @error('name')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group{{ $errors->has('note') ? ' form-group--error' : '' }}">
      <label for="note" class="admin-label">Note shown above the table (optional)</label>
      <textarea id="note" name="note" rows="2" maxlength="500" class="admin-input"
                placeholder="e.g. Measurements are in centimetres. If between sizes, size up.">{{ old('note', $guide['note']) }}</textarea>
      @error('note')<p class="form-group__error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="form-group">
      <span class="admin-label">Measurements</span>
      <p class="admin-help-text">The first column is always the size. Add columns for each measurement (up to {{ \App\Services\SizeGuideService::MAX_COLUMNS }}).</p>

      @if($errors->has('rows') || $errors->has('columns.*') || $errors->has('columns'))
        <p class="form-group__error" role="alert">{{ $errors->first('rows') ?: ($errors->first('columns.*') ?: $errors->first('columns')) }}</p>
      @endif

      <div class="admin-table-wrap">
        <table class="admin-table" id="sg-table">
          <thead>
            <tr id="sg-head">
              <th>Size</th>
              @foreach($columns as $col)
              <th>
                <div style="display:flex;gap:0.25rem;align-items:center;">
                  <input type="text" name="columns[]" value="{{ $col }}" required maxlength="50" class="admin-input" placeholder="Heading">
                  <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm js-del-col" title="Remove column" aria-label="Remove column">&times;</button>
                </div>
              </th>
              @endforeach
              <th></th>
            </tr>
          </thead>
          <tbody id="sg-body">
            @foreach($rows as $row)
            <tr>
              <td><input type="text" name="rows[][]" value="{{ $row[0] ?? '' }}" maxlength="50" class="admin-input" placeholder="S"></td>
              @foreach($columns as $j => $col)
              <td><input type="text" name="rows[][]" value="{{ $row[$j + 1] ?? '' }}" maxlength="50" class="admin-input"></td>
              @endforeach
              <td><button type="button" class="admin-btn admin-btn--ghost admin-btn--sm js-del-row" title="Remove row" aria-label="Remove row">&times;</button></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div style="display:flex;gap:0.5rem;margin-top:0.75rem;flex-wrap:wrap;">
        <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="sg-add-row">
          <i class="fas fa-plus" aria-hidden="true"></i> Add row
        </button>
        <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="sg-add-col">
          <i class="fas fa-plus" aria-hidden="true"></i> Add column
        </button>
      </div>
    </div>

    <button type="submit" class="admin-btn admin-btn--primary">{{ $isEdit ? 'Save changes' : 'Create size guide' }}</button>
    <a href="{{ url('/admin/products/size-guides') }}" class="admin-btn admin-btn--ghost">Cancel</a>
  </form>
</div>

<script>
(function () {
  var MAX_COLS = {{ \App\Services\SizeGuideService::MAX_COLUMNS }};
  var MAX_ROWS = {{ \App\Services\SizeGuideService::MAX_ROWS }};
  var head = document.getElementById('sg-head');
  var body = document.getElementById('sg-body');

  function colCount() { return head.querySelectorAll('input[name^="columns"]').length; }

  // Row inputs are named rows[i][j]; rebuild the indexes after any change.
  function renumber() {
    body.querySelectorAll('tr').forEach(function (tr, i) {
      tr.querySelectorAll('input').forEach(function (inp, j) { inp.name = 'rows[' + i + '][' + j + ']'; });
    });
    document.getElementById('sg-add-row').disabled = body.rows.length >= MAX_ROWS;
    document.getElementById('sg-add-col').disabled = colCount() >= MAX_COLS;
  }

  function cell() {
    var td = document.createElement('td');
    td.innerHTML = '<input type="text" maxlength="50" class="admin-input">';
    return td;
  }

  document.getElementById('sg-add-row').addEventListener('click', function () {
    if (body.rows.length >= MAX_ROWS) return;
    var tr = document.createElement('tr');
    for (var k = 0; k <= colCount(); k++) tr.appendChild(cell());
    var del = document.createElement('td');
    del.innerHTML = '<button type="button" class="admin-btn admin-btn--ghost admin-btn--sm js-del-row" title="Remove row" aria-label="Remove row">&times;</button>';
    tr.appendChild(del);
    body.appendChild(tr);
    renumber();
    tr.querySelector('input').focus();
  });

  document.getElementById('sg-add-col').addEventListener('click', function () {
    if (colCount() >= MAX_COLS) return;
    var th = document.createElement('th');
    th.innerHTML = '<div style="display:flex;gap:0.25rem;align-items:center;"><input type="text" name="columns[]" required maxlength="50" class="admin-input" placeholder="Heading"><button type="button" class="admin-btn admin-btn--ghost admin-btn--sm js-del-col" title="Remove column" aria-label="Remove column">&times;</button></div>';
    head.insertBefore(th, head.lastElementChild);
    body.querySelectorAll('tr').forEach(function (tr) { tr.insertBefore(cell(), tr.lastElementChild); });
    renumber();
    th.querySelector('input').focus();
  });

  document.getElementById('sg-form').addEventListener('click', function (e) {
    var delRow = e.target.closest('.js-del-row');
    if (delRow) {
      if (body.rows.length > 1) { delRow.closest('tr').remove(); renumber(); }
      return;
    }
    var delCol = e.target.closest('.js-del-col');
    if (delCol && colCount() > 1) {
      var idx = Array.prototype.indexOf.call(head.children, delCol.closest('th'));
      delCol.closest('th').remove();
      body.querySelectorAll('tr').forEach(function (tr) { tr.children[idx].remove(); });
      renumber();
    }
  });

  renumber();
})();
</script>

@endsection
