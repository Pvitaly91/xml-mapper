@extends('layouts.admin', ['title' => $feedProfile->name.' Operations'])

@section('subtitle', 'Production execution screen for sync, publish, first-pull verification, feedback import, rollback, and live cutover monitoring.')

@section('content')
    @php($panel = $operations)
    @php($cutover = $panel['cutover']['cutover'])
    @php($firstPull = $panel['first_pull']['latest'])
    @php($publishedGeneration = $panel['published_generation'])
    @php($latestGeneration = $panel['latest_generation'])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.promotion.show', $feedProfile) }}">Promotion center</a>
            <a class="button secondary" href="{{ route('admin.pilot-runs.index') }}">Pilot center</a>
            <a class="button secondary" href="{{ route('admin.merchant-launches.index') }}">Launch center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.show', $feedProfile) }}">War room</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.acceptance.show', $feedProfile) }}">Acceptance screen</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.rehearsal.show', $feedProfile) }}">Rehearsal</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reconciliation.show', $feedProfile) }}">Reconciliation</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback.create', $feedProfile) }}">Import feedback</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Rejection workbench</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.runbook.show', $feedProfile) }}">Download runbook</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.launch-pack.show', $feedProfile) }}">Launch pack</a>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Cutover</span><strong>{{ $cutover?->status ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Hypercare</span><strong>{{ $feedProfile->currentHypercareWindow?->status ?: 'inactive' }}</strong></div>
        <div class="stat"><span class="muted">Broken auth</span><strong>{{ $panel['broken_source_auth'] ? 'yes' : 'no' }}</strong></div>
        <div class="stat"><span class="muted">Failed jobs</span><strong>{{ $panel['failed_jobs_count'] }}</strong></div>
        <div class="stat"><span class="muted">Feedback rejected</span><strong>{{ $panel['feedback_summary']['rejected'] }}</strong></div>
        <div class="stat"><span class="muted">Feedback warnings</span><strong>{{ $panel['feedback_summary']['warnings'] }}</strong></div>
        <div class="stat"><span class="muted">Feedback open</span><strong>{{ $panel['feedback_summary']['open'] }}</strong></div>
        <div class="stat"><span class="muted">Last benchmark</span><strong>{{ optional($panel['maintenance']['last_benchmark']?->started_at)->format('H:i') ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Environment</span><strong>{{ $panel['environment']['label'] }}</strong></div>
        <div class="stat"><span class="muted">SLO</span><strong>{{ $panel['slo']['status'] ?? 'healthy' }}</strong></div>
        <div class="stat"><span class="muted">Promotion</span><strong>{{ $panel['promotion']['status'] }}</strong></div>
        <div class="stat"><span class="muted">Pilot</span><strong>{{ $panel['latest_pilot_run']?->state ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Pilot score</span><strong>{{ $panel['pilot_score']['score'] ?? 0 }}</strong></div>
        <div class="stat"><span class="muted">Launch</span><strong>{{ $panel['current_launch']?->state ?: 'n/a' }}</strong></div>
    </div>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Pilot Readiness</h2>
            <span class="badge {{ ($panel['pilot_score']['status'] ?? 'not_ready') === 'stable_after_launch' || ($panel['pilot_score']['status'] ?? 'not_ready') === 'ready' ? 'ok' : (($panel['pilot_score']['status'] ?? 'not_ready') === 'needs_attention' ? 'warn' : 'err') }}">{{ $panel['pilot_score']['status'] ?? 'not_ready' }}</span>
            @if($panel['latest_pilot_run'])
                <a class="button secondary" href="{{ route('admin.pilot-runs.show', $panel['latest_pilot_run']) }}">Open pilot run</a>
            @endif
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Latest run</strong><div>{{ $panel['latest_pilot_run']?->id ? '#'.$panel['latest_pilot_run']->id : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Current step</strong><div>{{ data_get($panel['latest_pilot_run']?->summary, 'execution.current_step_label', 'n/a') }}</div></div>
            <div class="detail-row"><strong>Next step</strong><div>{{ data_get($panel['latest_pilot_run']?->summary, 'execution.next_step_label', 'n/a') }}</div></div>
            <div class="detail-row"><strong>Blockers</strong><div>{{ implode(' ', $panel['pilot_score']['blocking_reasons'] ?? []) ?: 'none' }}</div></div>
        </div>
    </section>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Live Launch</h2>
            @if($panel['current_launch'])
                <span class="badge {{ in_array($panel['current_launch']->state, ['stabilized', 'closed'], true) ? 'ok' : (in_array($panel['current_launch']->state, ['degraded', 'failed', 'rolled_back'], true) ? 'err' : 'warn') }}">{{ $panel['current_launch']->state }}</span>
                <a class="button secondary" href="{{ route('admin.merchant-launches.show', $panel['current_launch']) }}">Open launch</a>
            @else
                <a class="button secondary" href="{{ route('admin.merchant-launches.index') }}">Start launch</a>
            @endif
        </div>
        @if($panel['current_launch'])
            <div class="detail-list">
                <div class="detail-row"><strong>Handover</strong><div>{{ $panel['current_launch']->handover_state }}</div></div>
                <div class="detail-row"><strong>Critical blockers</strong><div>{{ implode(' ', $panel['launch_check']['critical_blockers'] ?? []) ?: 'none' }}</div></div>
                <div class="detail-row"><strong>Next actions</strong><div>{{ implode(' | ', $panel['launch_check']['next_actions'] ?? []) ?: 'n/a' }}</div></div>
            </div>
        @else
            <p class="muted">No live launch record is open for this feed profile.</p>
        @endif
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Execution Timeline</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Last sync</strong><div>{{ optional($panel['last_sync'])->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last build</strong><div>{{ optional($panel['last_build'])->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last publish</strong><div>{{ optional($panel['last_publish'])->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last preview link</strong><div>{{ optional($panel['last_preview_event']?->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last smoke-check</strong><div>{{ $panel['last_smoke_check']?->status ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last first-pull verification</strong><div>{{ $firstPull?->status ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last rollback</strong><div>{{ optional($panel['last_rollback']?->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Promotion drift</strong><div>{{ $panel['promotion']['drift_status'] }}</div></div>
                <div class="detail-row"><strong>Secret rebind</strong><div>{{ $panel['promotion']['secret_rebind_pending'] ? 'pending' : 'clear' }}</div></div>
            </div>
        </section>

        <section class="panel">
            <h2>Current Cutover</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Status</strong><div>{{ $cutover?->status ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Planned window</strong><div>{{ optional($cutover?->planned_window_starts_at)->format('Y-m-d H:i') ?: 'n/a' }} to {{ optional($cutover?->planned_window_ends_at)->format('Y-m-d H:i') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Actual publish</strong><div>{{ optional($cutover?->actual_published_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>First verified</strong><div>{{ optional($cutover?->first_verified_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Publish allowed now</strong><div>{{ $panel['publish_window']['allowed_now'] ? 'yes' : 'no' }}</div></div>
                <div class="detail-row"><strong>Freeze mode</strong><div>{{ $panel['publish_window']['freeze_active'] ? 'active' : 'inactive' }}</div></div>
            </div>

            <form method="POST" action="{{ route('admin.feed-profiles.cutover', $feedProfile) }}" style="margin-top: 16px;">
                @csrf
                <input type="hidden" name="generation_id" value="{{ $latestGeneration?->id }}">
                <div class="form-grid">
                    <div class="field">
                        <label for="planned_window_starts_at">Planned window start</label>
                        <input id="planned_window_starts_at" type="datetime-local" name="planned_window_starts_at">
                    </div>
                    <div class="field">
                        <label for="planned_window_ends_at">Planned window end</label>
                        <input id="planned_window_ends_at" type="datetime-local" name="planned_window_ends_at">
                    </div>
                    <div class="field full">
                        <label for="cutover_note">Cutover note</label>
                        <input id="cutover_note" name="note" placeholder="Launch note or operator context">
                    </div>
                </div>
                <button class="button secondary" type="submit">Track cutover for latest generation</button>
            </form>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Staging Rehearsal</h2>
                <span class="badge {{ ($panel['rehearsal']['status'] ?? 'not_started') === 'passed' ? 'ok' : (($panel['rehearsal']['status'] ?? 'not_started') === 'blocked' ? 'warn' : (($panel['rehearsal']['status'] ?? 'not_started') === 'failed' ? 'err' : '')) }}">{{ $panel['rehearsal']['status'] ?? 'not_started' }}</span>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Latest run</strong><div>{{ optional($panel['rehearsal']['latest']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Current step</strong><div>{{ $panel['rehearsal']['current_step'] ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Canary publish</strong><div>{{ $panel['rehearsal']['rehearsal_publish_result'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>Canary smoke</strong><div>{{ $panel['rehearsal']['rehearsal_smoke_result']?->status ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Rollback rehearsal</strong><div>{{ $panel['rehearsal']['rehearsal_rollback_result'] ?? 'n/a' }}</div></div>
            </div>
            <form method="POST" action="{{ route('admin.feed-profiles.rehearsal.store', $feedProfile) }}" style="margin-top: 16px;">
                @csrf
                <label class="check"><input type="checkbox" name="with_sync" value="1"> With sync</label>
                <label class="check"><input type="checkbox" name="with_build" value="1"> With build</label>
                <label class="check"><input type="checkbox" name="with_preview" value="1" checked> With preview</label>
                <label class="check"><input type="checkbox" name="with_smoke" value="1" checked> With smoke</label>
                <label class="check"><input type="checkbox" name="with_rollback_check" value="1"> With rollback check</label>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Run rehearsal</button>
            </form>
        </section>

        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Restore Drill</h2>
                <span class="badge {{ ($panel['restore_drill']['latest']?->status ?? 'warning') === 'succeeded' ? 'ok' : (($panel['restore_drill']['latest']?->status ?? 'warning') === 'failed' ? 'err' : 'warn') }}">{{ $panel['restore_drill']['latest']?->status ?: 'n/a' }}</span>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Latest drill</strong><div>{{ optional($panel['restore_drill']['latest']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Report</strong><div>
                    @if($panel['restore_drill']['latest'])
                        <a class="button link" href="{{ route('admin.feed-profiles.restore-drill.show', [$feedProfile, $panel['restore_drill']['latest']]) }}">Download report</a>
                    @else
                        n/a
                    @endif
                </div></div>
            </div>
            <form method="POST" action="{{ route('admin.feed-profiles.restore-drill.store', $feedProfile) }}" style="margin-top: 16px;">
                @csrf
                <input type="text" name="note" placeholder="Restore drill note">
                <button class="button secondary" type="submit">Run restore drill</button>
            </form>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Maintenance</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Last preflight</strong><div>{{ optional($panel['maintenance']['last_preflight']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last DB backup</strong><div>{{ optional($panel['maintenance']['last_backup_db']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last files backup</strong><div>{{ optional($panel['maintenance']['last_backup_files']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last prune</strong><div>{{ optional($panel['maintenance']['last_prune']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last deploy</strong><div>{{ optional($panel['maintenance']['last_deploy']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Storage used</strong><div>{{ number_format(($panel['maintenance']['storage']['total_bytes'] ?? 0) / 1024 / 1024, 2) }} MB</div></div>
            </div>
        </section>
        <section class="panel">
            <h2>Queue / Retention</h2>
            <div class="detail-list">
                @foreach(($panel['maintenance']['queue_backlog'] ?? []) as $queue => $size)
                    <div class="detail-row"><strong>{{ $queue }}</strong><div>{{ $size ?? 'n/a' }}</div></div>
                @endforeach
            </div>
            @if(($panel['maintenance']['retention_warnings'] ?? []) !== [])
                <ul class="error-list" style="margin-top: 14px;">
                    @foreach($panel['maintenance']['retention_warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Reliability Summary</h2>
            <span class="badge {{ ($panel['slo']['status'] ?? 'healthy') === 'healthy' ? 'ok' : (($panel['slo']['status'] ?? 'healthy') === 'warning' ? 'warn' : 'err') }}">{{ $panel['slo']['status'] ?? 'healthy' }}</span>
        </div>
        <div class="stats">
            @php($slo24 = $panel['slo']['windows']['24h'] ?? null)
            @php($slo7d = $panel['slo']['windows']['168h'] ?? null)
            <div class="stat"><span class="muted">24h sync</span><strong>{{ $slo24 && ($slo24['sync']['rate'] ?? null) !== null ? number_format(($slo24['sync']['rate'] ?? 0) * 100, 1).'%' : 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">24h build</span><strong>{{ $slo24 && ($slo24['build']['rate'] ?? null) !== null ? number_format(($slo24['build']['rate'] ?? 0) * 100, 1).'%' : 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">24h publish</span><strong>{{ $slo24 && ($slo24['publish']['rate'] ?? null) !== null ? number_format(($slo24['publish']['rate'] ?? 0) * 100, 1).'%' : 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">24h first-pull</span><strong>{{ $slo24 && ($slo24['first_pull']['rate'] ?? null) !== null ? number_format(($slo24['first_pull']['rate'] ?? 0) * 100, 1).'%' : 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">7d status</span><strong>{{ $slo7d['status'] ?? 'n/a' }}</strong></div>
        </div>
    </section>

    <section class="panel">
        <h2>Direct Actions</h2>
        <div class="toolbar">
            @if($panel['source_connection'])
                <form method="POST" action="{{ route('admin.source-connections.sync', $panel['source_connection']) }}">
                    @csrf
                    <button class="button secondary" type="submit">Sync now</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}">
                @csrf
                <button class="button secondary" type="submit">Build candidate</button>
            </form>
            <form method="POST" action="{{ route('admin.feed-profiles.benchmark', $feedProfile) }}">
                @csrf
                <button class="button secondary" type="submit">Run benchmark</button>
            </form>
            <form method="POST" action="{{ route('admin.feed-profiles.rehearsal.store', $feedProfile) }}">
                @csrf
                <input type="hidden" name="with_preview" value="1">
                <input type="hidden" name="with_smoke" value="1">
                <button class="button secondary" type="submit">Rehearse launch</button>
            </form>
            @if($latestGeneration)
                <form method="POST" action="{{ route('admin.feed-profiles.generations.preview-links.store', [$feedProfile, $latestGeneration]) }}">
                    @csrf
                    <input type="hidden" name="ttl_minutes" value="1440">
                    <button class="button secondary" type="submit">Preview link</button>
                </form>
                <a class="button secondary" href="{{ route('admin.feed-profiles.generations.qa-bundle', [$feedProfile, $latestGeneration]) }}">QA bundle</a>
                <form method="POST" action="{{ route('admin.feed-profiles.generations.approve', [$feedProfile, $latestGeneration]) }}">
                    @csrf
                    <button class="button secondary" type="submit">Approve</button>
                </form>
            @endif
            @if($publishedGeneration)
                <form method="POST" action="{{ route('admin.feed-profiles.generations.first-pull-verify', [$feedProfile, $publishedGeneration]) }}">
                    @csrf
                    <button class="button secondary" type="submit">Run first-pull verification</button>
                </form>
                <form method="POST" action="{{ route('admin.feed-profiles.generations.smoke-check', [$feedProfile, $publishedGeneration]) }}">
                    @csrf
                    <button class="button secondary" type="submit">Rerun smoke check</button>
                </form>
                <form method="POST" action="{{ route('admin.feed-profiles.rollback', $feedProfile) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Rollback reason" required>
                    <input type="text" name="confirmation" placeholder="Type CONFIRM if required">
                    <button class="button danger" type="submit">Rollback</button>
                </form>
            @endif
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Incidents</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Action</th><th>User</th><th>Reason</th></tr></thead>
                    <tbody>
                    @forelse($panel['latest_incidents'] as $event)
                        <tr>
                            <td>{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                            <td>{{ $event->action }}</td>
                            <td>{{ $event->user?->email ?: 'system' }}</td>
                            <td>{{ $event->reason ?: 'n/a' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No incidents yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Warnings / Notifications</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Level</th><th>Event</th><th>Message</th></tr></thead>
                    <tbody>
                    @forelse($panel['latest_notifications'] as $log)
                        <tr>
                            <td>{{ optional($log->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                            <td>{{ $log->level }}</td>
                            <td>{{ $log->event }}</td>
                            <td>{{ $log->message }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No warnings or errors yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
