@extends('layouts.admin', ['title' => $feedProfile->name.' Reconciliation'])

@section('subtitle', 'Source vs normalized vs ready vs published diagnostics for first real merchant execution.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reports.reconciliation', $feedProfile) }}">Download JSON</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reports.reconciliation', ['feed_profile' => $feedProfile, 'format' => 'csv']) }}">Download CSV</a>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Source products</span><strong>{{ $report['summary']['source_products_total'] }}</strong></div>
        <div class="stat"><span class="muted">Source variants</span><strong>{{ $report['summary']['source_variants_total'] }}</strong></div>
        <div class="stat"><span class="muted">Normalized</span><strong>{{ $report['summary']['normalized_total'] }}</strong></div>
        <div class="stat"><span class="muted">Mapped</span><strong>{{ $report['summary']['mapped_total'] }}</strong></div>
        <div class="stat"><span class="muted">Ready</span><strong>{{ $report['summary']['ready_total'] }}</strong></div>
        <div class="stat"><span class="muted">Published</span><strong>{{ $report['summary']['published_total'] }}</strong></div>
    </div>

    <section class="panel">
        <div class="detail-list">
            <div class="detail-row"><strong>Excluded</strong><div>{{ $report['summary']['excluded_total'] }}</div></div>
            <div class="detail-row"><strong>Invalid</strong><div>{{ $report['summary']['invalid_total'] }}</div></div>
            <div class="detail-row"><strong>Source vs published delta</strong><div>{{ $report['summary']['deltas']['source_variants_vs_published'] }}</div></div>
            <div class="detail-row"><strong>Ready vs published delta</strong><div>{{ $report['summary']['deltas']['ready_vs_published'] }}</div></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Invalid Breakdown</h2>
            <ul>
                <li>invalid_source: {{ $report['invalid_breakdown']['invalid_source'] }}</li>
                <li>invalid_mapping: {{ $report['invalid_breakdown']['invalid_mapping'] }}</li>
                <li>invalid_conformance: {{ $report['invalid_breakdown']['invalid_conformance'] }}</li>
            </ul>
        </section>

        <section class="panel">
            <h2>Top Blockers</h2>
            @if($report['top_blockers'] !== [])
                <ul>
                    @foreach($report['top_blockers'] as $row)
                        <li>{{ $row['code'] }}: {{ $row['count'] }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No active blockers.</p>
            @endif
        </section>
    </div>

    <section class="panel">
        <form method="GET" action="{{ route('admin.feed-profiles.reconciliation.show', $feedProfile) }}" class="toolbar">
            <input type="text" name="blocker" value="{{ $filters['blocker'] ?? '' }}" placeholder="Filter by blocker code">
            <button class="button secondary" type="submit">Filter</button>
        </form>

        <div class="table-wrap" style="margin-top: 16px;">
            <table>
                <thead><tr><th>Feed item</th><th>Status</th><th>Variant</th><th>Source category</th><th>Blockers</th></tr></thead>
                <tbody>
                @forelse($report['breakdown'] as $row)
                    <tr>
                        <td>#{{ $row['feed_item_id'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['stable_offer_id'] ?: ('variant #'.$row['source_variant_id']) }}</td>
                        <td>{{ $row['source_category'] ?: 'n/a' }}</td>
                        <td>
                            @if($row['blockers'] === [])
                                <span class="muted">n/a</span>
                            @else
                                @foreach($row['blockers'] as $blocker)
                                    <div>{{ $blocker['code'] }}: {{ $blocker['message'] }}</div>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No reconciliation rows for current filter.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
