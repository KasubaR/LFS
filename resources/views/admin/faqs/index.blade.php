@extends('layouts.admin')

@section('content')
@php
$faqs        = $faqs        ?? [];
$faqCount    = $faqCount    ?? count($faqs);
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'FAQ'],
];
$csrfToken   = $csrfToken ?? '';
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">FAQs</h2>
  @if($faqCount < 10)
    <a href="/admin/faqs/create" class="admin-btn admin-btn--primary">
      <i class="fas fa-plus" aria-hidden="true"></i> Add FAQ
    </a>
  @else
    <span class="admin-text-dim"><i class="fas fa-lock" aria-hidden="true"></i> Maximum 10 FAQs reached</span>
  @endif
</div>

@if(empty($faqs))
  <p class="admin-empty">No FAQs yet.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Question</th>
          <th>Category</th>
          <th>Order</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($faqs as $f)
        <tr>
          <td class="cell-truncate">{{ mb_substr($f['question'] ?? '', 0, 80) }}{{ mb_strlen($f['question'] ?? '') > 80 ? '…' : '' }}</td>
          <td>{{ $f['category'] ?? '—' }}</td>
          <td>{{ (int)($f['sort_order'] ?? 0) }}</td>
          <td>{{ $f['created_at'] ?? '—' }}</td>
          <td class="cell-actions">
            <a href="/admin/faqs/{{ (int)($f['id']) }}/edit" class="admin-btn admin-btn--primary admin-btn--sm">Edit</a>
            <form method="post" action="/admin/faqs/{{ (int)($f['id']) }}/delete" onsubmit="return confirm('Delete this FAQ?');">
              <input type="hidden" name="_csrf" value="{{ $csrfToken }}">
              <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm">Delete</button>
            </form>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

@endsection
