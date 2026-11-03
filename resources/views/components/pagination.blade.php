@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        <span>
            @if (method_exists($paginator, 'total'))
                Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
            @endif
        </span>
        <span class="pagination__links">
            <a @class(['btn btn--secondary btn--sm', 'is-disabled' => $paginator->onFirstPage()]) href="{{ $paginator->previousPageUrl() }}" @if ($paginator->onFirstPage()) aria-disabled="true" @endif rel="prev">Previous</a>
            <a @class(['btn btn--secondary btn--sm', 'is-disabled' => ! $paginator->hasMorePages()]) href="{{ $paginator->nextPageUrl() }}" @unless ($paginator->hasMorePages()) aria-disabled="true" @endunless rel="next">Next</a>
        </span>
    </nav>
@endif
