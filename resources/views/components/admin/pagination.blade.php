@props(['paginator'])
@php
/** Shared admin pager for any LengthAwarePaginator: "Showing x–y of z" + windowed page buttons. */
$paginator->withQueryString();
$current = $paginator->currentPage();
$last = $paginator->lastPage();
$from = $paginator->total() === 0 ? 0 : (($current - 1) * $paginator->perPage()) + 1;
$to = min($current * $paginator->perPage(), $paginator->total());

$pages = collect([1, $last])->merge(range(max(1, $current - 2), min($last, $current + 2)))->unique()->sort()->values();
@endphp

@if($paginator->total() > 0)
  <nav aria-label="Pagination" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;margin-top:1rem;">
    <span class="admin-table__sub">Showing {{ number_format($from) }}–{{ number_format($to) }} of {{ number_format($paginator->total()) }}</span>

    @if($last > 1)
      <div style="display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;">
        @if($paginator->onFirstPage())
          <span class="admin-btn admin-btn--ghost admin-btn--sm" aria-disabled="true" style="opacity:.4;pointer-events:none;">&lsaquo; Prev</span>
        @else
          <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="admin-btn admin-btn--ghost admin-btn--sm">&lsaquo; Prev</a>
        @endif

        @php $prev = 0; @endphp
        @foreach($pages as $p)
          @if($p - $prev > 1)<span class="admin-table__sub">…</span>@endif
          <a href="{{ $paginator->url($p) }}"
             class="admin-btn admin-btn--sm {{ $p === $current ? 'admin-btn--primary' : 'admin-btn--ghost' }}"
             aria-current="{{ $p === $current ? 'page' : 'false' }}">{{ $p }}</a>
          @php $prev = $p; @endphp
        @endforeach

        @if($paginator->hasMorePages())
          <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="admin-btn admin-btn--ghost admin-btn--sm">Next &rsaquo;</a>
        @else
          <span class="admin-btn admin-btn--ghost admin-btn--sm" aria-disabled="true" style="opacity:.4;pointer-events:none;">Next &rsaquo;</span>
        @endif
      </div>
    @endif
  </nav>
@endif
