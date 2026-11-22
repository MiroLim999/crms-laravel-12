{{--
    The one pager for every list in CRMS (Saved Records, Audit Log), drawn with the
    theme's own pagination look: where you are on the left, ‹ 1 2 3 … › on the right.
    On a phone only the first, current and last page numbers stay.
    Set as the default in AppServiceProvider (Paginator::defaultView).
--}}
@if ($paginator->hasPages())
    <nav class="crms-pager" role="navigation" aria-label="Pagination Navigation">
        <p class="crms-pager__summary">
            @if ($paginator->firstItem())
                Showing <strong>{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</strong>
                of {{ number_format($paginator->total()) }}
            @else
                Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            @endif
        </p>

        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li class="page-item prev disabled" aria-disabled="true">
                    <span class="page-link" aria-label="Previous page">
                        <i class="icon-base bx bx-chevron-left icon-sm" aria-hidden="true"></i>
                    </span>
                </li>
            @else
                <li class="page-item prev">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev"
                       aria-label="Previous page" title="Previous page">
                        <i class="icon-base bx bx-chevron-left icon-sm" aria-hidden="true"></i>
                    </a>
                </li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled d-none d-sm-block" aria-disabled="true">
                        <span class="page-link">…</span>
                    </li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @php($keep = $page === $paginator->currentPage() || $page === 1 || $page === $paginator->lastPage())
                        @if ($page === $paginator->currentPage())
                            <li class="page-item active" aria-current="page">
                                <span class="page-link">{{ $page }}</span>
                            </li>
                        @else
                            <li class="page-item {{ $keep ? '' : 'd-none d-sm-block' }}">
                                <a class="page-link" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                            </li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item next">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next"
                       aria-label="Next page" title="Next page">
                        <i class="icon-base bx bx-chevron-right icon-sm" aria-hidden="true"></i>
                    </a>
                </li>
            @else
                <li class="page-item next disabled" aria-disabled="true">
                    <span class="page-link" aria-label="Next page">
                        <i class="icon-base bx bx-chevron-right icon-sm" aria-hidden="true"></i>
                    </span>
                </li>
            @endif
        </ul>
    </nav>
@endif
