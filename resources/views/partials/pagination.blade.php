@php
/**
 * PAGINATION PARTIAL — partials/pagination.blade.php
 *
 * Required variables:
 *   $currentPage      int     — 1-based current page
 *   $totalPages       int     — total number of pages
 *   $baseUrl          string  — URL prefix, e.g. "/gallery?page="
 *                               The partial appends the page number directly.
 * Optional:
 *   $paginationLabel  string  — aria-label for the <nav> (default: "Pagination")
 */

$_label = $paginationLabel ?? 'Pagination';
$_cur   = (int) ($currentPage ?? 1);
$_total = (int) ($totalPages  ?? 1);
if ($_cur   < 1) $_cur   = 1;
if ($_total < 1) $_total = 1;

/**
 * Build the window of visible page numbers.
 * Always shows: first page, last page, current ±2.
 * Fills gaps with null (rendered as …).
 */
$_pages = [];
if ($_total <= 7) {
    for ($_p = 1; $_p <= $_total; $_p++) {
        $_pages[] = $_p;
    }
} else {
    $_window = array_unique(array_merge(
        [1, $_total],
        range(max(1, $_cur - 2), min($_total, $_cur + 2))
    ));
    sort($_window);
    foreach ($_window as $_i => $_val) {
        if ($_i > 0 && $_val - $_window[$_i - 1] > 1) {
            $_pages[] = null; // ellipsis
        }
        $_pages[] = $_val;
    }
}
@endphp

@if($_total > 1)
<nav class="lfs-pagination" aria-label="{{ $_label }}">

  {{-- Prev --}}
  @if($_cur > 1)
    <a href="{{ $baseUrl }}{{ $_cur - 1 }}" class="lfs-pagination__btn" aria-label="Previous page">
      <i class="fas fa-chevron-left" aria-hidden="true"></i>
    </a>
  @else
    <span class="lfs-pagination__btn lfs-pagination__btn--disabled" aria-disabled="true" aria-label="Previous page">
      <i class="fas fa-chevron-left" aria-hidden="true"></i>
    </span>
  @endif

  {{-- Page numbers --}}
  @foreach($_pages as $pg)
    @if($pg === null)
      <span class="lfs-pagination__ellipsis" aria-hidden="true">&hellip;</span>
    @elseif($pg === $_cur)
      <span class="lfs-pagination__page lfs-pagination__page--active" aria-current="page" aria-label="Page {{ $pg }}">{{ $pg }}</span>
    @else
      <a href="{{ $baseUrl }}{{ $pg }}" class="lfs-pagination__page" aria-label="Page {{ $pg }}">{{ $pg }}</a>
    @endif
  @endforeach

  {{-- Next --}}
  @if($_cur < $_total)
    <a href="{{ $baseUrl }}{{ $_cur + 1 }}" class="lfs-pagination__btn" aria-label="Next page">
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
    </a>
  @else
    <span class="lfs-pagination__btn lfs-pagination__btn--disabled" aria-disabled="true" aria-label="Next page">
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
    </span>
  @endif

</nav>
@endif
