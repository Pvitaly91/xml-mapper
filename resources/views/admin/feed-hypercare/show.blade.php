@extends('layouts.admin', ['title' => $feedProfile->name.' War Room'])

@section('subtitle', 'Active hypercare dashboard for first live merchant monitoring, incidents, feedback follow-up, and closeout.')

@section('content')
    @php($panel = $dashboard)
    @php($hypercare = $panel['hypercare'])
    @php($publishedGeneration = $panel['published_generation'])
    @php($notificationSummary = $panel['notifications'] ?? ['recent' => collect(), 'failed_count' => 0, 'suppressed_count' => 0, 'escalated_count' => 0])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Release center</a>
            <a class="button secondary" href="{{ route('admin.merchant-launches.index') }}">Launch center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.timeline.show', $feedProfile) }}">Live timeline</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.digest', $feedProfile) }}">Daily digest</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.handoff', $feedProfile) }}">Shift handoff</a>
            <a class="button secondary" href="{{ route('admin.notifications.index', ['feed_profile_id' => $feedProfile->id]) }}">Notification center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback.create', $feedProfile) }}">Import feedback</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Remediation workbench</a>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Hypercare</span><strong>{{ $hypercare?->status ?: 'not_started' }}</strong></div>
        <div class="stat"><span class="muted">Risk state</span><strong>{{ $panel['risk_state'] }}</strong></div>
        <div class="stat"><span class="muted">Time since publish</span><strong>{{ $panel['time_since_publish'] ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Planned end</span><strong>{{ optional($hypercare?->planned_end_at)->format('Y-m-d H:i') ?: 'n/a' }}</strong></div>
        <div class="stat"><span class="muted">Blocking incidents</span><strong>{{ $panel['blocking_alerts']->count() }}</strong></div>
        <div class="stat"><span class="muted">Open alerts</span><strong>{{ $panel['alerts']->count() }}</strong></div>
        <div class="stat"><span class="muted">Feedback backlog</span><strong>{{ $panel['feedback']['pending_backlog'] ?? 0 }}</strong></div>
        <div class="stat"><span class="muted">Stability</span><strong>{{ $panel['stability']['score'] }} / {{ $panel['stability']['status'] }}</strong></div>
        <div class="stat"><span class="muted">Launch</span><strong>{{ $panel['current_launch']?->state ?: 'n/a' }}</strong></div>
    </div>

    <section class="panel">
        <h2>Outbound Notifications</h2>
        <div class="stats">
            <div class="stat"><span class="muted">Failed deliveries</span><strong>{{ $notificationSummary['failed_count'] ?? 0 }}</strong></div>
            <div class="stat"><span class="muted">Suppressed</span><strong>{{ $notificationSummary['suppressed_count'] ?? 0 }}</strong></div>
            <div class="stat"><span class="muted">Escalated</span><strong>{{ $notificationSummary['escalated_count'] ?? 0 }}</strong></div>
        </div>
        <div class="table-wrap" style="margin-top: 16px;">
            <table>
                <thead><tr><th>When</th><th>Event</th><th>Channel</th><th>Status</th><th>Correlation</th></tr></thead>
                <tbody>
                @forelse(($notificationSummary['recent'] ?? collect()) as $delivery)
                    <tr>
                        <td>{{ optional($delivery->created_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $delivery->event_type }}</td>
                        <td>{{ $delivery->channel }} / {{ $delivery->target_label ?: 'n/a' }}</td>
                        <td>{{ $delivery->status }}</td>
                        <td><code>{{ $delivery->correlation_id ?: 'n/a' }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No outbound notifications recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Current Hypercare</h2>
                @if($hypercare)
                    <span class="badge {{ in_array($hypercare->status, ['active', 'completed'], true) ? 'ok' : (in_array($hypercare->status, ['degraded', 'aborted'], true) ? 'err' : 'warn') }}">{{ $hypercare->status }}</span>
                @endif
            </div>
            @if($hypercare)
                <div class="detail-list">
                    <div class="detail-row"><strong>Owner</strong><div>{{ $hypercare->owner?->email ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Initiated by</strong><div>{{ $hypercare->initiatedBy?->email ?: 'system' }}</div></div>
                    <div class="detail-row"><strong>Started</strong><div>{{ optional($hypercare->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Window remaining</strong><div>{{ $hypercare->planned_end_at ? $hypercare->planned_end_at->diffForHumans() : 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Escalation level</strong><div>{{ $hypercare->escalation_level }}</div></div>
                    <div class="detail-row"><strong>Target SLA</strong><div>{{ $hypercare->target_sla_minutes }} minutes</div></div>
                    <div class="detail-row"><strong>Monitoring cadence</strong><div>{{ $hypercare->monitoring_cadence_minutes }} minutes</div></div>
                    <div class="detail-row"><strong>Note</strong><div>{{ $hypercare->note ?: 'n/a' }}</div></div>
                </div>
            @else
                <p class="muted">Hypercare has not started yet for this feed profile.</p>
                <form method="POST" action="{{ route('admin.feed-profiles.hypercare.start', $feedProfile) }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field">
                            <label for="start_hours">Hours</label>
                            <input id="start_hours" type="number" min="1" max="168" name="hours" value="24">
                        </div>
                        <div class="field full">
                            <label for="start_note">Note</label>
                            <input id="start_note" name="note" placeholder="Launch context or operator note">
                        </div>
                    </div>
                    <button class="button" type="submit" style="margin-top: 12px;">Start hypercare</button>
                </form>
            @endif
        </section>

        <section class="panel">
            <h2>Next Required Checks</h2>
            @if($panel['next_checks'] !== [])
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Policy</th><th>Status</th><th>Due</th></tr></thead>
                        <tbody>
                        @foreach($panel['next_checks'] as $check)
                            <tr>
                                <td>{{ $check['policy_key'] }}</td>
                                <td>{{ $check['status'] }}</td>
                                <td>{{ optional($check['due_at'])->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="muted">No upcoming checks are due yet.</p>
            @endif

            <div class="detail-list" style="margin-top: 16px;">
                <div class="detail-row"><strong>Latest smoke</strong><div>{{ $panel['latest_smoke']?->status ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Latest first-pull</strong><div>{{ $panel['latest_first_pull']?->status ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Latest sync</strong><div>{{ optional($panel['latest_sync'])->format('Y-m-d H:i:s') ?: 'n/a' }} ({{ $panel['latest_sync_status'] ?: 'n/a' }})</div></div>
            </div>
        </section>
    </div>

    @if($panel['current_launch'])
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Live Launch Support</h2>
                <a class="button secondary" href="{{ route('admin.merchant-launches.show', $panel['current_launch']) }}">Open launch</a>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Launch state</strong><div>{{ $panel['current_launch']->state }}</div></div>
                <div class="detail-row"><strong>Handover</strong><div>{{ $panel['current_launch']->handover_state }}</div></div>
                <div class="detail-row"><strong>Critical blockers</strong><div>{{ implode(' ', $panel['launch_check']['critical_blockers'] ?? []) ?: 'none' }}</div></div>
            </div>

            <form method="POST" action="{{ route('admin.merchant-launches.observations.store', $panel['current_launch']) }}" style="margin-top: 16px;">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="launch_obs_type">Observation type</label>
                        <select id="launch_obs_type" name="type">
                            <option value="merchant_confirmation">merchant confirmation</option>
                            <option value="first_marketplace_pickup_confirmed">first marketplace pickup confirmed</option>
                            <option value="unexpected_rejection_pattern">unexpected rejection pattern</option>
                            <option value="feed_delay_observed">feed delay observed</option>
                            <option value="image_or_content_issue_trend">image/content issue trend</option>
                            <option value="mapping_issue_discovered">mapping issue discovered</option>
                            <option value="performance_issue">performance issue</option>
                            <option value="false_alarm">false alarm</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="launch_obs_severity">Severity</label>
                        <select id="launch_obs_severity" name="severity">
                            <option value="low">low</option>
                            <option value="medium" selected>medium</option>
                            <option value="high">high</option>
                            <option value="critical">critical</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="launch_obs_source">Source</label>
                        <input id="launch_obs_source" name="source" value="hypercare">
                    </div>
                    <div class="field full">
                        <label for="launch_obs_note">Observation note</label>
                        <textarea id="launch_obs_note" name="note" required placeholder="Record merchant feedback, alert review, or live anomaly"></textarea>
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Record launch observation</button>
            </form>
        </section>
    @endif

    <div class="grid cols-2">
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Alerts / Incidents</h2>
                @if($panel['active_silence_window'])
                    <span class="badge warn">silence active</span>
                @endif
            </div>
            @if($panel['active_silence_window'])
                <div class="detail-list" style="margin-bottom: 16px;">
                    <div class="detail-row"><strong>Silence window</strong><div>{{ optional($panel['active_silence_window']->active_from)->format('Y-m-d H:i') ?: 'now' }} to {{ optional($panel['active_silence_window']->active_to)->format('Y-m-d H:i') ?: 'open-ended' }}</div></div>
                    <div class="detail-row"><strong>Threshold</strong><div>{{ $panel['active_silence_window']->severity_threshold }}</div></div>
                    <div class="detail-row"><strong>Created by</strong><div>{{ $panel['active_silence_window']->user?->email ?: 'system' }}</div></div>
                    <div class="detail-row"><strong>Reason</strong><div>{{ $panel['active_silence_window']->note ?: 'n/a' }}</div></div>
                </div>
            @endif

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Severity</th><th>Alert</th><th>State</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($panel['alerts'] as $alert)
                        <tr>
                            <td>{{ $alert->severity }}</td>
                            <td>
                                <strong>{{ $alert->title }}</strong><br>
                                <span class="muted">{{ $alert->message }}</span>
                                <br><span class="muted">corr: <code>{{ $alert->correlation_id ?: 'n/a' }}</code></span>
                            </td>
                            <td>{{ $alert->state }}</td>
                            <td>
                                @if(! in_array($alert->state, ['resolved', 'false_positive'], true))
                                    <form method="POST" action="{{ route('admin.feed-profiles.alerts.acknowledge', [$feedProfile, $alert]) }}" style="margin-bottom: 8px;">
                                        @csrf
                                        <input type="text" name="reason" placeholder="Acknowledge reason" required>
                                        <button class="button secondary" type="submit">Acknowledge</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.feed-profiles.alerts.resolve', [$feedProfile, $alert]) }}" style="margin-bottom: 8px;">
                                        @csrf
                                        <input type="text" name="reason" placeholder="Resolve reason" required>
                                        <button class="button secondary" type="submit">Resolve</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.feed-profiles.alerts.false-positive', [$feedProfile, $alert]) }}">
                                        @csrf
                                        <input type="text" name="reason" placeholder="False positive reason" required>
                                        <button class="button warning" type="submit">False positive</button>
                                    </form>
                                @else
                                    <span class="muted">Closed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No active alerts.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Feedback SLA / Rejections</h2>
            <div class="stats">
                <div class="stat"><span class="muted">Unmatched</span><strong>{{ $panel['feedback']['unmatched_feedback_count'] ?? 0 }}</strong></div>
                <div class="stat"><span class="muted">Open rejected</span><strong>{{ $panel['feedback']['open_rejected_items'] ?? 0 }}</strong></div>
                <div class="stat"><span class="muted">In progress</span><strong>{{ $panel['feedback']['in_progress'] ?? 0 }}</strong></div>
                <div class="stat"><span class="muted">Fixed</span><strong>{{ $panel['feedback']['fixed'] ?? 0 }}</strong></div>
                <div class="stat"><span class="muted">Won't fix</span><strong>{{ $panel['feedback']['wont_fix'] ?? 0 }}</strong></div>
                <div class="stat"><span class="muted">Avg ack</span><strong>{{ $panel['feedback']['average_time_to_acknowledge_minutes'] ?? 'n/a' }}</strong></div>
                <div class="stat"><span class="muted">Avg resolve</span><strong>{{ $panel['feedback']['average_time_to_resolve_minutes'] ?? 'n/a' }}</strong></div>
            </div>
            <div class="table-wrap" style="margin-top: 16px;">
                <table>
                    <thead><tr><th>Reason</th><th>Count</th></tr></thead>
                    <tbody>
                    @forelse(array_slice($panel['rejection_summary'], 0, 8) as $reason)
                        <tr>
                            <td>{{ $reason['reason_code'] }} / {{ $reason['reason_message'] }}</td>
                            <td>{{ $reason['count'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="muted">No rejection reasons recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Readiness / SLO / Ops</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Readiness</strong><div>{{ $panel['release_readiness']['status'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>SLO</strong><div>{{ $panel['slo']['status'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>Failed jobs</strong><div>{{ $panel['ops']['failed_jobs']['count'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Scheduler</strong><div>{{ $panel['ops']['scheduler_heartbeat']['status'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>Worker</strong><div>{{ $panel['ops']['worker_heartbeat']['status'] ?? 'n/a' }}</div></div>
            </div>
            <div class="table-wrap" style="margin-top: 16px;">
                <table>
                    <thead><tr><th>Queue</th><th>Backlog</th></tr></thead>
                    <tbody>
                    @foreach(($panel['maintenance']['queue_backlog'] ?? []) as $queue => $backlog)
                        <tr>
                            <td>{{ $queue }}</td>
                            <td>{{ $backlog ?? 'n/a' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Operator Actions</h2>
            <div class="toolbar">
                @if($publishedGeneration)
                    <form method="POST" action="{{ route('admin.feed-profiles.generations.smoke-check', [$feedProfile, $publishedGeneration]) }}">
                        @csrf
                        <button class="button secondary" type="submit">Rerun smoke</button>
                    </form>
                    <form method="POST" action="{{ route('admin.feed-profiles.generations.first-pull-verify', [$feedProfile, $publishedGeneration]) }}">
                        @csrf
                        <button class="button secondary" type="submit">Rerun first-pull verify</button>
                    </form>
                @endif
                <a class="button secondary" href="{{ route('admin.feed-profiles.feedback.create', $feedProfile) }}">Import feedback</a>
                <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Open workbench</a>
            </div>

            <div class="toolbar" style="margin-top: 16px;">
                <form method="POST" action="{{ route('admin.feed-profiles.hypercare.extend', $feedProfile) }}">
                    @csrf
                    <input type="number" name="hours" min="1" max="168" value="24">
                    <input type="text" name="note" placeholder="Extension note">
                    <button class="button secondary" type="submit">Extend hypercare</button>
                </form>
                <form method="POST" action="{{ route('admin.feed-profiles.hypercare.close', $feedProfile) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Closeout reason" required>
                    <button class="button" type="submit">Close hypercare</button>
                </form>
                <form method="POST" action="{{ route('admin.feed-profiles.hypercare.abort', $feedProfile) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Abort reason" required>
                    <button class="button danger" type="submit">Abort hypercare</button>
                </form>
            </div>

            <div class="toolbar" style="margin-top: 16px;">
                <form method="POST" action="{{ route('admin.feed-profiles.silence.store', $feedProfile) }}">
                    @csrf
                    <input type="datetime-local" name="from">
                    <input type="datetime-local" name="to">
                    <select name="severity">
                        <option value="critical">critical</option>
                        <option value="warning">warning</option>
                        <option value="info">info</option>
                    </select>
                    <input type="text" name="reason" placeholder="Maintenance reason" required>
                    <button class="button warning" type="submit">Start silence</button>
                </form>
                <form method="POST" action="{{ route('admin.feed-profiles.silence.clear', $feedProfile) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Why clear silence" required>
                    <button class="button warning" type="submit">Clear silence</button>
                </form>
            </div>

            <div class="toolbar" style="margin-top: 16px;">
                <form method="POST" action="{{ route('admin.feed-profiles.freeze', $feedProfile) }}">
                    @csrf
                    <input type="hidden" name="freeze" value="{{ $feedProfile->freezeModeActive() ? '0' : '1' }}">
                    <input type="text" name="reason" placeholder="Freeze toggle reason" required>
                    <button class="button warning" type="submit">{{ $feedProfile->freezeModeActive() ? 'Unfreeze feed' : 'Freeze feed' }}</button>
                </form>
                <form method="POST" action="{{ route('admin.feed-profiles.rollback', $feedProfile) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Rollback reason" required>
                    <button class="button danger" type="submit">Rollback</button>
                </form>
            </div>

            <form method="POST" action="{{ route('admin.feed-profiles.hypercare.note', $feedProfile) }}" style="margin-top: 16px;">
                @csrf
                <div class="field">
                    <label for="hypercare_note">Operator note</label>
                    <textarea id="hypercare_note" name="body" placeholder="Manual note for shift handoff / incident timeline" required></textarea>
                </div>
                <button class="button secondary" type="submit">Add note</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <h2>Recent Timeline</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Type</th><th>Severity</th><th>Message</th></tr></thead>
                <tbody>
                @forelse($panel['timeline_preview'] as $event)
                    <tr>
                        <td>{{ $event['occurred_at'] }}</td>
                        <td>{{ $event['event_type'] }}</td>
                        <td>{{ $event['severity'] }}</td>
                        <td><strong>{{ $event['title'] }}</strong><br><span class="muted">{{ $event['message'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No live timeline events yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
