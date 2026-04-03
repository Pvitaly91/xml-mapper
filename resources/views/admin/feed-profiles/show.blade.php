@extends('layouts.admin', ['title' => $feedProfile->name])

@section('subtitle', 'Manual build/publish workflow, pilot readiness, generation diff, and feed-item diagnostics.')

@section('content')
    @php($latestSummary = $pilotReadiness['generation_summary'])
    @php($latestGuard = $pilotReadiness['publish_guard'])
    @php($currentHypercare = $hypercareSummary['current'])

    <section class="panel">
        <div class="toolbar">
            <a class="button link" href="{{ route('admin.feed-profiles.edit', $feedProfile) }}">Edit</a>
            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}">
                @csrf
                <button class="button" type="submit">Build now</button>
            </form>
            <a class="button secondary" href="{{ route('admin.shop-control.show') }}">Go-live control panel</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.acceptance.show', $feedProfile) }}">Acceptance screen</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.promotion.show', $feedProfile) }}">Promotion center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.show', $feedProfile) }}">War room</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reconciliation.show', $feedProfile) }}">Reconciliation</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Rejection workbench</a>
            <form method="POST" action="{{ route('admin.feed-profiles.status', $feedProfile) }}">
                @csrf
                <input type="hidden" name="status" value="{{ $feedProfile->status === 'active' ? 'inactive' : 'active' }}">
                <button class="button warning" type="submit">{{ $feedProfile->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
            </form>
            <a class="button secondary" href="{{ route('admin.feed-profiles.index') }}">Back</a>
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Code</strong><div>{{ $feedProfile->code }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $feedProfile->status }}</div></div>
            <div class="detail-row"><strong>Source connection</strong><div>{{ $feedProfile->sourceConnection?->name ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Include unavailable</strong><div>{{ $feedProfile->include_unavailable ? 'Yes' : 'No' }}</div></div>
            <div class="detail-row"><strong>Publish guard</strong><div>{{ $feedProfile->publishGuardEnabled() ? 'Enabled' : 'Disabled' }}</div></div>
            <div class="detail-row"><strong>Last build status</strong><div>{{ $feedProfile->latestGeneration?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Published generation</strong><div>{{ $feedProfile->publishedGeneration?->id ? '#'.$feedProfile->publishedGeneration->id : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Hypercare</strong><div>{{ $currentHypercare?->status ?: 'inactive' }}</div></div>
            <div class="detail-row"><strong>Promotion status</strong><div>{{ $promotionStatus['status'] }}</div></div>
            <div class="detail-row"><strong>Promotion drift</strong><div>{{ $promotionStatus['drift_status'] }}</div></div>
            <div class="detail-row"><strong>Secret rebind</strong><div>{{ $promotionStatus['secret_rebind_pending'] ? 'pending' : 'clear' }}</div></div>
            <div class="detail-row"><strong>Public feed URL</strong><div>{{ $publicFeedUrl ?: 'n/a' }}</div></div>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Feed items</span><strong>{{ $feedItemStats['total'] }}</strong></div>
        <div class="stat"><span class="muted">Ready</span><strong>{{ $feedItemStats['ready'] }}</strong></div>
        <div class="stat"><span class="muted">Published</span><strong>{{ $feedItemStats['published'] }}</strong></div>
        <div class="stat"><span class="muted">Invalid</span><strong>{{ $feedItemStats['invalid'] }}</strong></div>
        <div class="stat"><span class="muted">Excluded</span><strong>{{ $feedItemStats['excluded'] }}</strong></div>
        <div class="stat"><span class="muted">Active validation errors</span><strong>{{ $activeValidationErrors }}</strong></div>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Pilot Readiness</h2>
                <span class="badge {{ $latestGuard['allowed'] ? 'ok' : 'warn' }}">{{ $latestGuard['allowed'] ? 'allowed' : 'blocked' }}</span>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Source synced</strong><div>{{ $pilotReadiness['source_synced']['ok'] ? 'Yes' : 'No' }} ({{ $pilotReadiness['source_synced']['status'] }})</div></div>
                <div class="detail-row"><strong>Mappings complete</strong><div>{{ $pilotReadiness['mappings_complete']['ok'] ? 'Yes' : 'No' }}</div></div>
                <div class="detail-row"><strong>Dictionaries imported</strong><div>{{ $pilotReadiness['dictionaries_imported']['ok'] ? 'Yes' : 'No' }}</div></div>
                <div class="detail-row"><strong>Ready items</strong><div>{{ $latestSummary['ready'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Invalid items</strong><div>{{ $latestSummary['invalid_total'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Excluded items</strong><div>{{ $latestSummary['excluded'] ?? 0 }}</div></div>
            </div>
            @if(! empty($latestGuard['reasons']))
                <ul style="margin-top: 14px;">
                    @foreach($latestGuard['reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel">
            <h2>Generation Preview Summary</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Total</strong><div>{{ $latestSummary['total'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Ready</strong><div>{{ $latestSummary['ready'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Invalid source</strong><div>{{ $latestSummary['invalid_source'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Invalid mapping</strong><div>{{ $latestSummary['invalid_mapping'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Invalid conformance</strong><div>{{ $latestSummary['invalid_conformance'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Excluded</strong><div>{{ $latestSummary['excluded'] ?? 0 }}</div></div>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.category-mappings.index', $feedProfile) }}">Category mappings</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.attribute-mappings.index', $feedProfile) }}">Attribute mappings</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.value-mappings.index', $feedProfile) }}">Value mappings</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feed-items.index', $feedProfile) }}">Feed items</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.workbench.index', $feedProfile) }}">Unresolved workbench</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.mapping-presets.import', $feedProfile) }}">Mapping presets</a>
        </div>
    </section>

    <section class="panel">
        <h2>Recent Generations</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Status</th><th>Summary</th><th>Diff</th><th>Publish guard</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($recentGenerations as $generation)
                    @php($summary = $generation->meta['summary'] ?? null)
                    @php($diff = $generation->meta['diff']['summary'] ?? null)
                    @php($guard = $generation->meta['publish_guard'] ?? null)
                    <tr>
                        <td>#{{ $generation->id }}</td>
                        <td>
                            {{ $generation->status }}<br>
                            <span class="muted">{{ optional($generation->built_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</span>
                        </td>
                        <td>
                            @if($summary)
                                <div><strong>Total:</strong> {{ $summary['total'] ?? 0 }}</div>
                                <div><strong>Ready:</strong> {{ $summary['ready'] ?? 0 }}</div>
                                <div><strong>Invalid:</strong> {{ $summary['invalid_total'] ?? 0 }}</div>
                                <div><strong>Excluded:</strong> {{ $summary['excluded'] ?? 0 }}</div>
                            @else
                                <span class="muted">n/a</span>
                            @endif
                        </td>
                        <td>
                            @if($diff)
                                <div><strong>Added:</strong> {{ $diff['added_items_total'] ?? 0 }}</div>
                                <div><strong>Removed:</strong> {{ $diff['removed_items_total'] ?? 0 }}</div>
                                <div><strong>Changed:</strong> {{ $diff['changed_items_total'] ?? 0 }}</div>
                                <div class="muted">price {{ $diff['changed_fields']['price'] ?? 0 }}, availability {{ $diff['changed_fields']['availability'] ?? 0 }}, categoryId {{ $diff['changed_fields']['categoryId'] ?? 0 }}, vendorCode {{ $diff['changed_fields']['vendorCode'] ?? 0 }}</div>
                            @else
                                <span class="muted">No diff yet.</span>
                            @endif
                        </td>
                        <td>
                            @if($guard)
                                <div><strong>{{ ($guard['allowed'] ?? false) ? 'Allowed' : 'Blocked' }}</strong></div>
                                @if(! empty($guard['reasons']))
                                    @foreach($guard['reasons'] as $reason)
                                        <div class="muted">{{ $reason }}</div>
                                    @endforeach
                                @endif
                            @else
                                <span class="muted">n/a</span>
                            @endif
                        </td>
                        <td>
                            <a class="button link" href="{{ route('admin.feed-profiles.generations.show', [$feedProfile, $generation]) }}">Open release details</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No generations yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
