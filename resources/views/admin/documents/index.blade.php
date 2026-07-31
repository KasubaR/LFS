@extends('layouts.admin')

@section('content')
@php
$documents = $documents ?? [];
$breadcrumbs = [
    ['label' => 'Admin', 'url' => '/admin/dashboard'],
    ['label' => 'Documents'],
];
$csrfToken = $csrfToken ?? '';
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">Document Library</h2>
  <a href="/admin/documents/create" class="admin-btn admin-btn--primary">
    <i class="fas fa-plus" aria-hidden="true"></i> Upload Document
  </a>
</div>

@if(empty($documents))
  <p class="admin-empty">No documents yet. Upload constitution, policies, forms, and more for members.</p>
@else
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Category</th>
          <th>Status</th>
          <th>File</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($documents as $doc)
        <tr>
          <td class="cell-truncate">{{ mb_substr($doc['title'] ?? '', 0, 80) }}{{ mb_strlen($doc['title'] ?? '') > 80 ? '…' : '' }}</td>
          <td>{{ $doc['categoryLabel'] ?? '—' }}</td>
          <td>
            @if($doc['isPublished'] ?? false)
              <span class="status-pill status-pill--green">Published</span>
            @else
              <span class="status-pill status-pill--muted">Hidden</span>
            @endif
          </td>
          <td class="cell-truncate">{{ $doc['originalFilename'] ?? '—' }}</td>
          <td class="cell-actions">
            <a href="/admin/documents/{{ $doc['id'] }}/edit" class="admin-btn admin-btn--primary admin-btn--sm">Edit</a>
            <form method="post" action="/admin/documents/{{ $doc['id'] }}/delete" onsubmit="return confirm('Delete this document?');">
              @csrf
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
