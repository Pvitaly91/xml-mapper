@extends('layouts.admin', ['title' => 'Performance Run'])

@section('subtitle', 'Detailed stage timings, counts, budget evaluation, and report download for one persisted performance run.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.performance.index', ['feed_profile_id' => $run->feed_profile_id]) }}">Back to performance center</a>
            <a class="button secondary" href="{{ route('admin.performance.report', $run) }}">Download report</a>
            @if($run->feedProfile)
                <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $run->feedProfile) }}">Feed operations</a>
            @endif
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Run</strong><div>#{{ $run->id }} / {{ $run->run_type }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $run->status }}</div></div>
            <div class="detail-row"><strong>Budget</strong><div>{{ $run->budget_status }}</div></div>
            <div class="detail-row"><strong>Feed profile</strong><div>{{ $run->feedProfile?->name ? $run->feedProfile->name.' ('.$run->feedProfile->code.')' : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Dataset</strong><div>{{ number_format($run->dataset_products) }} products / {{ number_format($run->dataset_variants) }} variants</div></div>
            <div class="detail-row"><strong>Duration</strong><div>{{ $run->duration_ms !== null ? number_format($run->duration_ms).' ms' : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Peak memory</strong><div>{{ $run->peak_memory_mb !== null ? number_format($run->peak_memory_mb, 2).' MB' : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Started</strong><div>{{ optional($run->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Finished</strong><div>{{ optional($run->finished_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
        </div>
        @if(($run->warnings ?? []) !== [])
            <ul class="error-list" style="margin-top: 14px;">
                @foreach($run->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="panel">
        <h2>Stages</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Stage</th><th>Status</th><th>Budget</th><th>Duration</th><th>Processed</th><th>Warnings / Errors</th></tr></thead>
                <tbody>
                @foreach($run->stageRuns as $stage)
                    <tr>
                        <td>{{ $stage->stage }}</td>
                        <td>{{ $stage->status }}</td>
                        <td>{{ $stage->budget_status }}</td>
                        <td>{{ $stage->duration_ms !== null ? number_format($stage->duration_ms).' ms' : 'n/a' }}</td>
                        <td>
                            {{ number_format($stage->processed_products) }} products /
                            {{ number_format($stage->processed_variants) }} variants /
                            {{ number_format($stage->processed_rows) }} rows
                        </td>
                        <td>
                            @foreach((array) ($stage->warnings ?? []) as $warning)
                                <div>{{ $warning }}</div>
                            @endforeach
                            @foreach((array) ($stage->errors ?? []) as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Summary</h2>
        <pre>{{ json_encode($run->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre>
    </section>
@endsection
