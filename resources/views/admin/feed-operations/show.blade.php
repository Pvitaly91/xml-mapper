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
            <a class="button secondary" href="{{ route('admin.feed-profiles.acceptance.show', $feedProfile) }}">Acceptance screen</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reconciliation.show', $feedProfile) }}">Reconciliation</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback.create', $feedProfile) }}">Import feedback</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Rejection workbench</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.runbook.show', $feedProfile) }}">Download runbook</a>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Cutover</span><strong>{{ $cutover?->status ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Broken auth</span><strong>{{ $panel['broken_source_auth'] ? 'yes' : 'no' }}</strong></div>
        <div class="stat"><span class="muted">Failed jobs</span><strong>{{ $panel['failed_jobs_count'] }}</strong></div>
        <div class="stat"><span class="muted">Feedback rejected</span><strong>{{ $panel['feedback_summary']['rejected'] }}</strong></div>
        <div class="stat"><span class="muted">Feedback warnings</span><strong>{{ $panel['feedback_summary']['warnings'] }}</strong></div>
        <div class="stat"><span class="muted">Feedback open</span><strong>{{ $panel['feedback_summary']['open'] }}</strong></div>
    </div>

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
