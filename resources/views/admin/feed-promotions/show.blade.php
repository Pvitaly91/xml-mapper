@extends('layouts.admin', ['title' => $feedProfile->name.' Promotion Center'])

@section('subtitle', 'Staging-to-production config snapshot, drift compare, dry-run, apply, rollback, and secret-safe source rebinding workflow.')

@section('content')
    @php($status = $center['status'])
    @php($snapshots = $center['snapshots'])
    @php($runs = $center['runs'])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.rehearsal.show', $feedProfile) }}">Rehearsal</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            @if($feedProfile->sourceConnection)
                <a class="button secondary" href="{{ route('admin.source-connections.show', $feedProfile->sourceConnection) }}">Source connection</a>
            @endif
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Environment</strong><div>{{ $center['environment']['label'] }}</div></div>
            <div class="detail-row"><strong>Promotion status</strong><div>{{ $status['status'] }}</div></div>
            <div class="detail-row"><strong>Latest drift</strong><div>{{ $status['drift_status'] }}</div></div>
            <div class="detail-row"><strong>Promotion needed</strong><div>{{ $status['promotion_needed'] === null ? 'unknown' : ($status['promotion_needed'] ? 'yes' : 'no') }}</div></div>
            <div class="detail-row"><strong>Secret rebind pending</strong><div>{{ $status['secret_rebind_pending'] ? 'yes' : 'no' }}</div></div>
            <div class="detail-row"><strong>Secret state</strong><div>{{ $status['secret_state'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Current checksum</strong><div>{{ $status['current_checksum'] }}</div></div>
            <div class="detail-row"><strong>Latest local snapshot</strong><div>{{ $status['latest_snapshot']?->checksum ?: 'n/a' }}</div></div>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Snapshots</span><strong>{{ $snapshots->count() }}</strong></div>
        <div class="stat"><span class="muted">Runs</span><strong>{{ $runs->count() }}</strong></div>
        <div class="stat"><span class="muted">Latest compare</span><strong>{{ $status['latest_compare']?->status ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Latest target apply</span><strong>{{ $status['latest_target_apply']?->status ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Latest source apply</span><strong>{{ $status['latest_source_apply']?->status ?: 'n/a' }}</strong></div>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Generate Snapshot</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.promotion.snapshot', $feedProfile) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="snapshot_env">Source env class</label>
                        <input id="snapshot_env" name="env" value="{{ old('env', $center['environment']['class']) }}">
                    </div>
                    <div class="field">
                        <label for="snapshot_label">Source env label</label>
                        <input id="snapshot_label" name="label" value="{{ old('label', $center['environment']['label']) }}">
                    </div>
                    <div class="field full">
                        <label for="snapshot_name">Snapshot name</label>
                        <input id="snapshot_name" name="name" value="{{ old('name', $feedProfile->code.' promotion snapshot') }}">
                    </div>
                </div>
                <button class="button secondary" type="submit">Generate local snapshot</button>
            </form>
            <p class="muted" style="margin-top: 12px;">Snapshot exports non-secret config only. Tokens and credentials are never transferred in plaintext.</p>
        </section>

        <section class="panel">
            <h2>Import External Snapshot</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.promotion.import', $feedProfile) }}" enctype="multipart/form-data">
                @csrf
                <div class="form-grid">
                    <div class="field full">
                        <label for="snapshot_file">Snapshot JSON</label>
                        <input id="snapshot_file" type="file" name="snapshot_file" required>
                    </div>
                    <div class="field full">
                        <label for="import_name">Imported snapshot name</label>
                        <input id="import_name" name="name" value="{{ old('name') }}">
                    </div>
                </div>
                <button class="button secondary" type="submit">Import snapshot</button>
            </form>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Compare / Dry-Run / Apply</h2>
            @if($snapshots->isEmpty())
                <p class="muted">Generate or import a snapshot first.</p>
            @else
                <form method="POST" action="{{ route('admin.feed-profiles.promotion.compare', $feedProfile) }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field full">
                            <label for="compare_snapshot">Snapshot</label>
                            <select id="compare_snapshot" name="source_snapshot_id">
                                @foreach($snapshots as $snapshot)
                                    <option value="{{ $snapshot->id }}">#{{ $snapshot->id }} | {{ $snapshot->environment_class }} | {{ $snapshot->checksum }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field full">
                            <label for="compare_reason">Reason</label>
                            <input id="compare_reason" name="reason" placeholder="Why this compare is being run">
                        </div>
                    </div>
                    <button class="button secondary" type="submit">Compare drift</button>
                </form>

                <form method="POST" action="{{ route('admin.feed-profiles.promotion.dry-run', $feedProfile) }}" style="margin-top: 16px;">
                    @csrf
                    <div class="form-grid">
                        <div class="field full">
                            <label for="dry_run_snapshot">Snapshot</label>
                            <select id="dry_run_snapshot" name="source_snapshot_id">
                                @foreach($snapshots as $snapshot)
                                    <option value="{{ $snapshot->id }}">#{{ $snapshot->id }} | {{ $snapshot->environment_class }} | {{ $snapshot->checksum }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="dry_run_strategy">Strategy</label>
                            <select id="dry_run_strategy" name="strategy">
                                @foreach(\App\Models\PromotionRun::strategies() as $strategy)
                                    <option value="{{ $strategy }}">{{ $strategy }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="dry_run_reason">Reason</label>
                            <input id="dry_run_reason" name="reason" placeholder="Dry-run note">
                        </div>
                    </div>
                    <button class="button secondary" type="submit">Dry-run promotion</button>
                </form>

                <form method="POST" action="{{ route('admin.feed-profiles.promotion.apply', $feedProfile) }}" style="margin-top: 16px;">
                    @csrf
                    <div class="form-grid">
                        <div class="field full">
                            <label for="apply_snapshot">Snapshot</label>
                            <select id="apply_snapshot" name="source_snapshot_id">
                                @foreach($snapshots as $snapshot)
                                    <option value="{{ $snapshot->id }}">#{{ $snapshot->id }} | {{ $snapshot->environment_class }} | {{ $snapshot->checksum }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="apply_strategy">Strategy</label>
                            <select id="apply_strategy" name="strategy">
                                @foreach(\App\Models\PromotionRun::strategies() as $strategy)
                                    <option value="{{ $strategy }}">{{ $strategy }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="apply_reason">Reason</label>
                            <input id="apply_reason" name="reason" placeholder="Why this promotion is being applied">
                        </div>
                    </div>
                    <button class="button" type="submit">Apply promotion</button>
                </form>
            @endif
        </section>

        <section class="panel">
            <h2>Current Guardrails</h2>
            <ul>
                <li>Snapshots carry mappings, overrides, publish rules, onboarding state, dictionary fingerprints, and source metadata shape.</li>
                <li>Secrets are not copied. Target connections keep existing tokens or require operator re-entry.</li>
                <li>Rollback is config-level only and is blocked when target config drifted after the original apply.</li>
                <li>Dry-run is required to see creates, updates, skips, conflicts, warnings, and blocking errors before apply.</li>
            </ul>
        </section>
    </div>

    <section class="panel">
        <h2>Snapshots</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Env</th><th>Checksum</th><th>Fingerprints</th><th>Generated</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($snapshots as $snapshot)
                    <tr>
                        <td>#{{ $snapshot->id }}</td>
                        <td>{{ $snapshot->environment_label ?: $snapshot->environment_class }}</td>
                        <td>{{ $snapshot->checksum }}</td>
                        <td>
                            <div class="muted">Mappings: {{ $snapshot->mapping_fingerprint ?: 'n/a' }}</div>
                            <div class="muted">Settings: {{ $snapshot->settings_fingerprint ?: 'n/a' }}</div>
                            <div class="muted">Source: {{ $snapshot->source_connection_fingerprint ?: 'n/a' }}</div>
                        </td>
                        <td>{{ optional($snapshot->generated_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            <a class="button link" href="{{ route('admin.feed-profiles.promotion.snapshots.download', [$feedProfile, $snapshot]) }}">Download JSON</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No snapshots yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Promotion History</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Mode</th><th>Status</th><th>Strategy</th><th>Source env</th><th>Started</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($runs as $run)
                    <tr>
                        <td>#{{ $run->id }}</td>
                        <td>{{ $run->mode }}</td>
                        <td>{{ $run->status }}</td>
                        <td>{{ $run->strategy ?: 'n/a' }}</td>
                        <td>{{ $run->source_environment ?: 'n/a' }}</td>
                        <td>{{ optional($run->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            <a class="button link" href="{{ route('admin.feed-profiles.promotion.runs.show', [$feedProfile, $run]) }}">Details</a>
                            <a class="button link" href="{{ route('admin.feed-profiles.promotion.runs.download', [$feedProfile, $run]) }}">Report</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No promotion runs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
