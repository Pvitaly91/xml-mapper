@extends('layouts.admin', ['title' => 'Launch #'.$launch->id])

@section('subtitle', 'Real-run checklist for the first live merchant launch: baseline vs actual, observations, defects, tuning, stabilization, and handover.')

@section('safety_banner')
    <strong>Launch closeout is guarded</strong>
    Use this screen to capture live evidence first. If blockers still exist, handover or closeout may require governance review instead of executing immediately.
@endsection

@section('content')
    @php($feedProfile = $launch->feedProfile)
    @php($baselineMetrics = $baseline['metrics'] ?? [])
    @php($checklist = $handover['checklist']['items'] ?? [])
    @php($notificationSummary = $notifications ?? ['recent' => collect(), 'failed_count' => 0, 'suppressed_count' => 0, 'escalated_count' => 0])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.merchant-launches.index') }}">Back to Launch Center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Feed profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.show', $feedProfile) }}">War room</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Remediation</a>
            <a class="button secondary" href="{{ route('admin.notifications.index', ['feed_profile_id' => $feedProfile->id]) }}">Notification center</a>
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">State</span><strong>{{ $launch->state }}</strong></div>
            <div class="stat"><span class="muted">Handover</span><strong>{{ $launch->handover_state }}</strong></div>
            <div class="stat"><span class="muted">Open incidents</span><strong>{{ $check['open_incidents'] }}</strong></div>
            <div class="stat"><span class="muted">Open defects</span><strong>{{ $check['open_defects'] }}</strong></div>
            <div class="stat"><span class="muted">Actual publish</span><strong>{{ optional($launch->actual_published_at)->format('Y-m-d H:i') ?: 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">Go-live confirm</span><strong>{{ optional($launch->actual_go_live_confirmed_at)->format('Y-m-d H:i') ?: 'n/a' }}</strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Launch Summary</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Feed profile</strong><div>{{ $feedProfile->name }} ({{ $feedProfile->code }})</div></div>
                <div class="detail-row"><strong>Pilot run</strong><div>{{ $launch->pilotRun?->id ? '#'.$launch->pilotRun->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Promotion run</strong><div>{{ $launch->promotionRun?->id ? '#'.$launch->promotionRun->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Published generation</strong><div>{{ $launch->publishedGeneration?->id ? '#'.$launch->publishedGeneration->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Owner</strong><div>{{ $launch->owner?->email ?: 'unassigned' }}</div></div>
                <div class="detail-row"><strong>Initiated by</strong><div>{{ $launch->initiatedBy?->email ?: 'system' }}</div></div>
                <div class="detail-row"><strong>Environment</strong><div>{{ $launch->environment_label ?: $launch->environment_class }}</div></div>
                <div class="detail-row"><strong>Planned start</strong><div>{{ optional($launch->planned_start_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Outcome</strong><div>{{ $launch->outcome ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Note</strong><div>{{ $launch->note ?: 'n/a' }}</div></div>
            </div>
        </section>

        <section class="panel">
            <h2>Launch Check</h2>
            @if(($check['critical_blockers'] ?? []) !== [])
                <ul class="error-list">
                    @foreach($check['critical_blockers'] as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No critical blockers are open.</p>
            @endif

            <h3>Next Actions</h3>
            <ul>
                @foreach($next_actions as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ul>
        </section>
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
                    <tr><td colspan="5" class="muted">No outbound notifications recorded for this launch.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Performance Signals</h2>
            <span class="badge {{ ($performance['latest']?->budget_status ?? 'within_budget') === 'critical' ? 'err' : (($performance['latest']?->budget_status ?? 'within_budget') === 'warning' ? 'warn' : 'ok') }}">{{ $performance['latest']?->budget_status ?: 'within_budget' }}</span>
            <a class="button secondary" href="{{ route('admin.performance.index', ['feed_profile_id' => $feedProfile->id]) }}">Open performance center</a>
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Latest run</strong><div>{{ $performance['latest']?->run_type ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Duration</strong><div>{{ $performance['latest']?->duration_ms ? number_format($performance['latest']->duration_ms).' ms' : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Regression</strong><div>{{ data_get($performance, 'compare.overall.message', 'No comparison yet.') }}</div></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Baseline vs Actual</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Metric</th><th>Expected</th><th>Actual</th><th>Delta</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($baselineMetrics as $metric => $row)
                        <tr>
                            <td>{{ $metric }}</td>
                            <td>{{ $row['expected'] ?? 'n/a' }}</td>
                            <td>{{ $row['actual'] ?? 'n/a' }}</td>
                            <td>{{ $row['delta'] ?? 'n/a' }}</td>
                            <td>{{ $row['status'] ?? 'pending' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('admin.merchant-launches.baseline.update', $launch) }}" style="margin-top: 16px;">
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="field">
                        <label for="expected_ready_items">Expected ready items</label>
                        <input id="expected_ready_items" type="number" min="0" name="expected_ready_items" value="{{ $launch->expected_ready_items }}">
                    </div>
                    <div class="field">
                        <label for="expected_published_count">Expected published count</label>
                        <input id="expected_published_count" type="number" min="0" name="expected_published_count" value="{{ $launch->expected_published_count }}">
                    </div>
                    <div class="field">
                        <label for="expected_first_pull_latency_ms">Expected first-pull latency (ms)</label>
                        <input id="expected_first_pull_latency_ms" type="number" min="0" name="expected_first_pull_latency_ms" value="{{ $launch->expected_first_pull_latency_ms }}">
                    </div>
                    <div class="field">
                        <label for="expected_sync_freshness_minutes">Expected sync freshness (minutes)</label>
                        <input id="expected_sync_freshness_minutes" type="number" min="0" name="expected_sync_freshness_minutes" value="{{ $launch->expected_sync_freshness_minutes }}">
                    </div>
                    <div class="field">
                        <label for="expected_feedback_total">Expected feedback total</label>
                        <input id="expected_feedback_total" type="number" min="0" name="expected_feedback_total" value="{{ $launch->expected_feedback_total }}">
                    </div>
                    <div class="field">
                        <label for="expected_rejection_total">Expected rejection total</label>
                        <input id="expected_rejection_total" type="number" min="0" name="expected_rejection_total" value="{{ $launch->expected_rejection_total }}">
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Update baseline</button>
            </form>
        </section>

        <section class="panel">
            <h2>Stabilization Checklist</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                    <tbody>
                    @foreach($checklist as $name => $item)
                        <tr>
                            <td>{{ $name }}</td>
                            <td>{{ $item['ok'] ? 'ok' : 'blocked' }}</td>
                            <td>{{ $item['detail'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Quick Actions</h2>
            <div class="toolbar">
                @if($launch->publishedGeneration)
                    <form method="POST" action="{{ route('admin.feed-profiles.generations.smoke-check', [$feedProfile, $launch->publishedGeneration]) }}">
                        @csrf
                        <button class="button secondary" type="submit">Rerun smoke</button>
                    </form>
                    <form method="POST" action="{{ route('admin.feed-profiles.generations.first-pull-verify', [$feedProfile, $launch->publishedGeneration]) }}">
                        @csrf
                        <button class="button secondary" type="submit">Rerun first-pull verify</button>
                    </form>
                @endif
                <a class="button secondary" href="{{ route('admin.feed-profiles.feedback.create', $feedProfile) }}">Import feedback</a>
                <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Open remediation</a>
                <form method="POST" action="{{ route('admin.feed-profiles.rollback', $feedProfile) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Rollback reason" required>
                    <button class="button danger" type="submit">Rollback</button>
                </form>
            </div>

            <div class="toolbar" style="margin-top: 16px;">
                <a class="button link" href="{{ route('admin.merchant-launches.reports.show', [$launch, 'summary']) }}">Summary report</a>
                <a class="button link" href="{{ route('admin.merchant-launches.reports.show', [$launch, 'observations']) }}">Observation report</a>
                <a class="button link" href="{{ route('admin.merchant-launches.reports.show', [$launch, 'defects']) }}">Defect report</a>
                <a class="button link" href="{{ route('admin.merchant-launches.reports.show', [$launch, 'closeout']) }}">Closeout report</a>
            </div>

            <form method="POST" action="{{ route('admin.merchant-launches.handover', $launch) }}" style="margin-top: 16px;">
                @csrf
                <div class="field">
                    <label for="handover_reason">Handover reason</label>
                    <input id="handover_reason" name="reason" placeholder="Why the launch is stable for handover" required>
                </div>
                <button class="button" type="submit" style="margin-top: 12px;" data-testid="launch-handover-submit">Handover launch</button>
            </form>

            <form method="POST" action="{{ route('admin.merchant-launches.close', $launch) }}" style="margin-top: 16px;">
                @csrf
                <div class="field">
                    <label for="close_reason">Close reason</label>
                    <input id="close_reason" name="reason" placeholder="Closeout note or final decision" required>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;" data-testid="launch-close-submit">Close launch</button>
            </form>
        </section>

        <section class="panel">
            <h2>Apply Tuning</h2>
            <form method="POST" action="{{ route('admin.merchant-launches.tuning.store', $launch) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="tuning_type">Type</label>
                        <select id="tuning_type" name="type">
                            <option value="publish_guard">Publish guard</option>
                            <option value="excluded_category">Excluded category</option>
                            <option value="excluded_vendor">Excluded vendor</option>
                            <option value="minimum_image_count">Minimum image count</option>
                            <option value="minimum_price">Minimum price</option>
                            <option value="forced_attribute_override">Forced attribute override</option>
                            <option value="forced_value_override">Forced value override</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="tuning_mode">Mode</label>
                        <select id="tuning_mode" name="mode">
                            <option value="normal">normal</option>
                            <option value="emergency">emergency</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="tuning_key">Key</label>
                        <input id="tuning_key" name="key" placeholder="publish guard key / override key">
                    </div>
                    <div class="field">
                        <label for="tuning_value">Value</label>
                        <input id="tuning_value" name="value" placeholder="Value">
                    </div>
                    <div class="field full">
                        <label for="tuning_reason">Reason</label>
                        <textarea id="tuning_reason" name="reason" required placeholder="Why this tuning is needed for the live launch"></textarea>
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Apply tuning</button>
            </form>

            <h3 style="margin-top: 18px;">Recent tuning</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Type</th><th>Mode</th><th>Reason</th><th>User</th></tr></thead>
                    <tbody>
                    @forelse($tuning_actions as $action)
                        <tr>
                            <td>{{ optional($action->applied_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                            <td>{{ $action->type }}</td>
                            <td>{{ $action->mode }}</td>
                            <td>{{ $action->reason }}</td>
                            <td>{{ $action->user?->email ?: 'system' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No tuning actions yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Add Observation</h2>
            <form method="POST" action="{{ route('admin.merchant-launches.observations.store', $launch) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="obs_type">Type</label>
                        <select id="obs_type" name="type">
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
                        <label for="obs_severity">Severity</label>
                        <select id="obs_severity" name="severity">
                            <option value="low">low</option>
                            <option value="medium" selected>medium</option>
                            <option value="high">high</option>
                            <option value="critical">critical</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="obs_source">Source</label>
                        <input id="obs_source" name="source" value="operator">
                    </div>
                    <div class="field full">
                        <label for="obs_note">Note</label>
                        <textarea id="obs_note" name="note" required placeholder="Merchant call, marketplace pickup, alert review, or manual inspection note"></textarea>
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Record observation</button>
            </form>

            <h3 style="margin-top: 18px;">Observations</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Type</th><th>Severity</th><th>Source</th><th>Note</th></tr></thead>
                    <tbody>
                    @forelse($observations as $observation)
                        <tr>
                            <td>{{ optional($observation->observed_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                            <td>{{ $observation->type }}</td>
                            <td>{{ $observation->severity }}</td>
                            <td>{{ $observation->source }}</td>
                            <td>{{ $observation->note }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No observations yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 14px;">{{ $observations->links() }}</div>
        </section>

        <section class="panel">
            <h2>Open Defect</h2>
            <form method="POST" action="{{ route('admin.merchant-launches.defects.store', $launch) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="defect_type">Type</label>
                        <select id="defect_type" name="type">
                            <option value="data_quality">data quality</option>
                            <option value="mapping_gap">mapping gap</option>
                            <option value="source_sync_issue">source sync issue</option>
                            <option value="export_conformance_issue">export conformance issue</option>
                            <option value="feedback_matching_issue">feedback matching issue</option>
                            <option value="performance_issue">performance issue</option>
                            <option value="ops_issue">ops issue</option>
                            <option value="false_positive">false positive</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="defect_severity">Severity</label>
                        <select id="defect_severity" name="severity">
                            <option value="low">low</option>
                            <option value="medium" selected>medium</option>
                            <option value="high">high</option>
                            <option value="critical">critical</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label for="defect_title">Title</label>
                        <input id="defect_title" name="title" placeholder="Short defect title">
                    </div>
                    <div class="field">
                        <label for="defect_feed_item_id">Feed item ID</label>
                        <input id="defect_feed_item_id" type="number" min="1" name="feed_item_id" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label for="defect_feedback_record_id">Feedback record ID</label>
                        <input id="defect_feedback_record_id" type="number" min="1" name="feedback_record_id" placeholder="Optional">
                    </div>
                    <div class="field full">
                        <label for="defect_note">Note</label>
                        <textarea id="defect_note" name="note" required placeholder="Observed issue, scope, and immediate impact"></textarea>
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Open defect</button>
            </form>

            <h3 style="margin-top: 18px;">Defects</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Defect</th><th>Status</th><th>Links</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($defects as $defect)
                        <tr>
                            <td>
                                <strong>{{ $defect->title }}</strong><br>
                                <span class="muted">{{ $defect->type }} / {{ $defect->severity }}</span><br>
                                <span class="muted">{{ $defect->note }}</span>
                            </td>
                            <td>{{ $defect->status }}</td>
                            <td>
                                @if($defect->feedItem)
                                    <a class="button link" href="{{ route('admin.feed-profiles.feed-items.show', [$feedProfile, $defect->feedItem]) }}">Feed item</a>
                                @endif
                                @if($defect->feedbackRecord)
                                    <a class="button link" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Feedback</a>
                                @endif
                                <a class="button link" href="{{ route('admin.feed-profiles.attribute-mappings.index', $feedProfile) }}">Mappings</a>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('admin.merchant-launches.defects.update', [$launch, $defect]) }}">
                                    @csrf
                                    @method('PUT')
                                    <select name="status">
                                        @foreach(['open','triaged','fixing','mitigated','resolved','wont_fix'] as $status)
                                            <option value="{{ $status }}" @selected($defect->status === $status)>{{ $status }}</option>
                                        @endforeach
                                    </select>
                                    <select name="severity">
                                        @foreach(['low','medium','high','critical'] as $severity)
                                            <option value="{{ $severity }}" @selected($defect->severity === $severity)>{{ $severity }}</option>
                                        @endforeach
                                    </select>
                                    <input name="resolution_note" placeholder="Resolution note">
                                    <button class="button secondary" type="submit">Update</button>
                                </form>
                                @if($defect->feedItem)
                                    <form method="POST" action="{{ route('admin.feed-profiles.feed-items.override', [$feedProfile, $defect->feedItem]) }}" style="margin-top: 8px;">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="is_enabled" value="0">
                                        <input type="hidden" name="excluded_reason" value="Excluded during launch defect triage #{{ $defect->id }}">
                                        <button class="button warning" type="submit">Exclude item</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}" style="margin-top: 8px;">
                                    @csrf
                                    <button class="button secondary" type="submit">Rebuild candidate</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No launch defects yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 14px;">{{ $defects->links() }}</div>
        </section>
    </div>

    <section class="panel">
        <h2>Alerts / History</h2>
        <div class="grid cols-2">
            <div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Severity</th><th>Alert</th><th>State</th></tr></thead>
                        <tbody>
                        @forelse($open_alerts as $alert)
                            <tr>
                                <td>{{ $alert->severity }}</td>
                                <td><strong>{{ $alert->title }}</strong><br><span class="muted">{{ $alert->message }}</span><br><span class="muted">corr: <code>{{ $alert->correlation_id ?: 'n/a' }}</code></span></td>
                                <td>{{ $alert->state }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">No open alerts.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>When</th><th>Action</th><th>User</th><th>Reason</th></tr></thead>
                        <tbody>
                        @forelse($history as $event)
                            <tr>
                                <td>{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                                <td>{{ $event->action }}</td>
                                <td>{{ $event->user?->email ?: 'system' }}</td>
                                <td>{{ $event->reason ?: 'n/a' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted">No launch history yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
@endsection
