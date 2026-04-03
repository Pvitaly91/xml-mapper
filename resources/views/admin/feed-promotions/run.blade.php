@extends('layouts.admin', ['title' => 'Promotion Run #'.$run->id])

@section('subtitle', 'Detailed drift, dry-run, apply, or rollback report for merchant promotion workflow.')

@section('content')
    @php($summary = $run->summary ?? [])
    @php($plan = $summary['plan'] ?? [])
    @php($drift = $summary['drift'] ?? [])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.promotion.show', $feedProfile) }}">Back to promotion center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.promotion.runs.download', [$feedProfile, $run]) }}">Download report</a>
            @if($run->canRollback())
                <form method="POST" action="{{ route('admin.feed-profiles.promotion.runs.rollback', [$feedProfile, $run]) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Rollback reason">
                    <button class="button warning" type="submit">Rollback config</button>
                </form>
            @endif
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Mode</strong><div>{{ $run->mode }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $run->status }}</div></div>
            <div class="detail-row"><strong>Strategy</strong><div>{{ $run->strategy ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Source env</strong><div>{{ $run->source_environment ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Target env</strong><div>{{ $run->target_environment ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Started</strong><div>{{ optional($run->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Finished</strong><div>{{ optional($run->finished_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Reason</strong><div>{{ $run->reason ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Source snapshot</strong><div>{{ $run->sourceSnapshot?->checksum ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Target baseline snapshot</strong><div>{{ $run->targetSnapshot?->checksum ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Result snapshot</strong><div>{{ $run->resultSnapshot?->checksum ?: 'n/a' }}</div></div>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Created</span><strong>{{ data_get($plan, 'summary.created', 0) }}</strong></div>
        <div class="stat"><span class="muted">Updated</span><strong>{{ data_get($plan, 'summary.updated', 0) }}</strong></div>
        <div class="stat"><span class="muted">Skipped</span><strong>{{ data_get($plan, 'summary.skipped', 0) }}</strong></div>
        <div class="stat"><span class="muted">Conflicts</span><strong>{{ data_get($plan, 'summary.conflicts', 0) }}</strong></div>
        <div class="stat"><span class="muted">Blocking errors</span><strong>{{ data_get($plan, 'summary.blocking_errors', count($run->errors ?? [])) }}</strong></div>
        <div class="stat"><span class="muted">Secret rebind</span><strong>{{ data_get($summary, 'secret_rebind.required', false) ? 'required' : 'no' }}</strong></div>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Warnings</h2>
            @if(($run->warnings ?? []) !== [])
                <ul>
                    @foreach($run->warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No warnings.</p>
            @endif
        </section>
        <section class="panel">
            <h2>Errors</h2>
            @if(($run->errors ?? []) !== [])
                <ul class="error-list">
                    @foreach($run->errors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No blocking errors.</p>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Drift Summary</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Status</strong><div>{{ $drift['status'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>Shop changes</strong><div>{{ data_get($drift, 'summary.shop_changes', 0) }}</div></div>
                <div class="detail-row"><strong>Onboarding changes</strong><div>{{ data_get($drift, 'summary.onboarding_changes', 0) }}</div></div>
                <div class="detail-row"><strong>Feed settings changes</strong><div>{{ data_get($drift, 'summary.feed_settings_changes', 0) }}</div></div>
                <div class="detail-row"><strong>Changed mappings</strong><div>{{ data_get($drift, 'summary.changed_mappings', 0) }}</div></div>
                <div class="detail-row"><strong>Missing mappings</strong><div>{{ data_get($drift, 'summary.missing_mappings', 0) }}</div></div>
                <div class="detail-row"><strong>Dictionary mismatches</strong><div>{{ data_get($drift, 'summary.dictionary_mismatches', 0) }}</div></div>
            </div>
        </section>
        <section class="panel">
            <h2>Secret Rebind</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Required</strong><div>{{ data_get($summary, 'secret_rebind.required', false) ? 'yes' : 'no' }}</div></div>
                <div class="detail-row"><strong>State</strong><div>{{ data_get($summary, 'secret_rebind.state', 'n/a') }}</div></div>
                <div class="detail-row"><strong>Fields</strong><div>{{ implode(', ', data_get($summary, 'secret_rebind.required_fields', [])) ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Edit route</strong><div>{{ data_get($summary, 'secret_rebind.edit_route', 'n/a') }}</div></div>
            </div>
        </section>
    </div>

    @if(($plan['operations'] ?? []) !== [])
        @foreach($plan['operations'] as $section => $operations)
            <section class="panel">
                <h2>{{ str_replace('_', ' ', ucfirst($section)) }}</h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Action</th><th>Label</th><th>Reason</th></tr></thead>
                        <tbody>
                        @forelse($operations as $operation)
                            <tr>
                                <td>{{ $operation['action'] ?? 'n/a' }}</td>
                                <td>{{ $operation['label'] ?? ($operation['identity_key'] ?? 'n/a') }}</td>
                                <td>{{ $operation['reason'] ?? 'n/a' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">No operations recorded.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    @endif
@endsection
