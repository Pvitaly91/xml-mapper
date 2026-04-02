@if($paginator->hasPages())
    <div class="toolbar" style="justify-content: space-between; margin-top: 14px;">
        <div class="muted">Showing {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}</div>
        <div class="toolbar">
            @if($paginator->onFirstPage())
                <span class="button secondary" style="opacity: 0.55;">Previous</span>
            @else
                <a class="button secondary" href="{{ $paginator->previousPageUrl() }}">Previous</a>
            @endif
            <span class="badge">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @if($paginator->hasMorePages())
                <a class="button secondary" href="{{ $paginator->nextPageUrl() }}">Next</a>
            @else
                <span class="button secondary" style="opacity: 0.55;">Next</span>
            @endif
        </div>
    </div>
@endif
