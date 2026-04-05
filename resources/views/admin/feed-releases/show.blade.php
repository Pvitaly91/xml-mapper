@extends('layouts.admin', ['title' => $feedProfile->name.' Release Center'])

@section('subtitle', 'Approval, publish, rollback, smoke checks, and operator reports for pilot releases.')

@section('safety_banner')
    <strong>Release actions are environment-aware</strong>
    Freeze, force publish, rollback, and promotion-linked actions can require step-up re-authentication or approval before execution.
@endsection

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.show', $feedProfile) }}">War room</a>
            <a class="button secondary" href="{{ route('admin.pilot-runs.index') }}">Pilot center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.promotion.show', $feedProfile) }}">Promotion center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.rehearsal.show', $feedProfile) }}">Rehearsal</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.launch-pack.show', $feedProfile) }}">Launch pack</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reconciliation.show', $feedProfile) }}">Reconciliation</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.content-enrichment.index', $feedProfile) }}">Content enrichment</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Rejection workbench</a>
            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}">
                @csrf
                <button class="button secondary" type="submit">Build now</button>
            </form>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reports.invalid-items', $feedProfile) }}">Invalid items CSV</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reports.invalid-items', ['feed_profile' => $feedProfile, 'format' => 'json']) }}">Invalid items JSON</a>
            @if($publicFeedUrl)
                <a class="button link" href="{{ $publicFeedUrl }}" target="_blank" rel="noreferrer">Open published feed</a>
            @endif
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Source connection</strong><div>{{ $feedProfile->sourceConnection?->name ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Published generation</strong><div>{{ $feedProfile->publishedGeneration?->id ? '#'.$feedProfile->publishedGeneration->id : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Latest generation</strong><div>{{ $latestGeneration?->id ? '#'.$latestGeneration->id : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Public feed URL</strong><div>{{ $publicFeedUrl ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Freeze mode</strong><div>{{ $publishWindow['freeze_active'] ? 'active' : 'inactive' }}</div></div>
            <div class="detail-row"><strong>Publish now</strong><div>{{ $publishWindow['allowed_now'] ? 'allowed' : 'blocked' }}</div></div>
            <div class="detail-row"><strong>Next allowed window</strong><div>{{ $publishWindow['next_allowed_at'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Cutover status</strong><div>{{ $cutoverSummary['cutover']?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Hypercare</strong><div>{{ $feedProfile->currentHypercareWindow?->status ?: 'inactive' }}</div></div>
            <div class="detail-row"><strong>Promotion status</strong><div>{{ $promotionStatus['status'] }}</div></div>
            <div class="detail-row"><strong>Promotion drift</strong><div>{{ $promotionStatus['drift_status'] }}</div></div>
            <div class="detail-row"><strong>Secret rebind</strong><div>{{ $promotionStatus['secret_rebind_pending'] ? 'pending' : 'clear' }}</div></div>
        </div>

        <div class="toolbar" style="margin-top: 14px;">
            <form method="POST" action="{{ route('admin.feed-profiles.freeze', $feedProfile) }}" data-testid="release-freeze-form">
                @csrf
                <input type="hidden" name="freeze" value="{{ $publishWindow['freeze_active'] ? '0' : '1' }}">
                <input type="text" name="reason" placeholder="{{ $publishWindow['freeze_active'] ? 'Reason to unfreeze' : 'Reason to freeze' }}" required data-testid="release-freeze-reason">
                <input type="text" name="confirmation" placeholder="Type CONFIRM if required">
                <button class="button warning" type="submit" data-testid="release-freeze-submit">{{ $publishWindow['freeze_active'] ? 'Disable freeze' : 'Enable freeze' }}</button>
            </form>
            <a class="button secondary" href="{{ route('admin.feed-profiles.acceptance.show', $feedProfile) }}">Acceptance screen</a>
        </div>
    </section>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Pilot Execution</h2>
            <span class="badge {{ ($pilotScore['status'] ?? 'not_ready') === 'stable_after_launch' || ($pilotScore['status'] ?? 'not_ready') === 'ready' ? 'ok' : (($pilotScore['status'] ?? 'not_ready') === 'needs_attention' ? 'warn' : 'err') }}">{{ $pilotScore['status'] ?? 'not_ready' }}</span>
            @if($latestPilotRun)
                <a class="button secondary" href="{{ route('admin.pilot-runs.show', $latestPilotRun) }}">Open latest run</a>
            @endif
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Latest pilot run</strong><div>{{ $latestPilotRun?->id ? '#'.$latestPilotRun->id : 'n/a' }}</div></div>
            <div class="detail-row"><strong>State</strong><div>{{ $latestPilotRun?->state ?: 'not started' }}</div></div>
            <div class="detail-row"><strong>Next step</strong><div>{{ data_get($latestPilotRun?->summary, 'execution.next_step_label', 'n/a') }}</div></div>
            <div class="detail-row"><strong>Pilot score</strong><div>{{ $pilotScore['score'] ?? 0 }}/100</div></div>
            <div class="detail-row"><strong>Blocking reasons</strong><div>{{ implode(' ', $pilotScore['blocking_reasons'] ?? []) ?: 'none' }}</div></div>
        </div>
    </section>

    @if(($rehearsalSummary['latest'] ?? null) || ($appEnvironment['warnings'] ?? []) !== [])
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Staging Rehearsal</h2>
                <span class="badge {{ ($rehearsalSummary['status'] ?? 'not_started') === 'passed' ? 'ok' : (($rehearsalSummary['status'] ?? 'not_started') === 'blocked' ? 'warn' : (($rehearsalSummary['status'] ?? 'not_started') === 'failed' ? 'err' : '')) }}">{{ $rehearsalSummary['status'] ?? 'not_started' }}</span>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Environment</strong><div>{{ $appEnvironment['label'] }}</div></div>
                <div class="detail-row"><strong>Candidate prepared</strong><div>{{ ($rehearsalSummary['rehearsal_candidate'] ?? false) ? 'yes' : 'no' }}</div></div>
                <div class="detail-row"><strong>Canary publish</strong><div>{{ $rehearsalSummary['rehearsal_publish_result'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>Canary smoke</strong><div>{{ $rehearsalSummary['rehearsal_smoke_result']?->status ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Rollback rehearsal</strong><div>{{ $rehearsalSummary['rehearsal_rollback_result'] ?? 'n/a' }}</div></div>
            </div>
        </section>
    @endif

    @if($latestReadiness)
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Go-Live Checklist</h2>
                <span class="badge {{ $latestReadiness['status'] === 'ready' ? 'ok' : ($latestReadiness['status'] === 'blocked' ? 'err' : 'warn') }}">{{ $latestReadiness['status'] }}</span>
            </div>
            <div class="grid cols-2">
                <div>
                    <h3>Blocking issues</h3>
                    @if($latestReadiness['blocking_issues'] !== [])
                        <ul>
                            @foreach($latestReadiness['blocking_issues'] as $issue)
                                <li>{{ $issue }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="muted">No blocking issues.</p>
                    @endif
                </div>
                <div>
                    <h3>Warnings / next steps</h3>
                    @if($latestReadiness['warnings'] !== [] || $latestReadiness['next_steps'] !== [])
                        <ul>
                            @foreach($latestReadiness['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                            @foreach($latestReadiness['next_steps'] as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="muted">No warnings.</p>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Promotion Parity</h2>
            <span class="badge {{ $promotionStatus['status'] === 'in_sync' ? 'ok' : ($promotionStatus['status'] === 'unknown' ? 'warn' : 'err') }}">{{ $promotionStatus['status'] }}</span>
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Current checksum</strong><div>{{ $promotionStatus['current_checksum'] }}</div></div>
            <div class="detail-row"><strong>Latest compare</strong><div>{{ $promotionStatus['latest_compare']?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Latest target apply</strong><div>{{ $promotionStatus['latest_target_apply']?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Promotion needed</strong><div>{{ $promotionStatus['promotion_needed'] === null ? 'unknown' : ($promotionStatus['promotion_needed'] ? 'yes' : 'no') }}</div></div>
        </div>
    </section>

    @if(($cutoverSummary['blocking_issues'] ?? []) !== [] || ($cutoverSummary['warnings'] ?? []) !== [])
        <section class="panel">
            <h2>Production Cutover</h2>
            @if(($cutoverSummary['blocking_issues'] ?? []) !== [])
                <h3>Blocking issues</h3>
                <ul>
                    @foreach($cutoverSummary['blocking_issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @endif
            @if(($cutoverSummary['warnings'] ?? []) !== [])
                <h3>Warnings</h3>
                <ul>
                    @foreach($cutoverSummary['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    <section class="panel">
        <h2>Generations</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Built at</th>
                    <th>Counts</th>
                    <th>Release</th>
                    <th>Smoke check</th>
                    <th>Publish guard</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($generations as $generation)
                    @php($summary = $generation->meta['summary'] ?? [])
                    @php($guard = $generation->meta['publish_guard'] ?? ['allowed' => false, 'reasons' => []])
                    @php($currentSignoff = $generation->signoffs->first())
                    <tr>
                        <td>
                            <strong>#{{ $generation->id }}</strong><br>
                            <span class="muted">{{ $generation->status }}</span>
                        </td>
                        <td>{{ optional($generation->built_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            <div><strong>Ready:</strong> {{ $summary['ready'] ?? 0 }}</div>
                            <div><strong>Invalid:</strong> {{ $summary['invalid_total'] ?? 0 }}</div>
                            <div><strong>Excluded:</strong> {{ $summary['excluded'] ?? 0 }}</div>
                        </td>
                        <td>
                            <span class="badge {{ in_array($generation->release_status, ['published', 'approved'], true) ? 'ok' : (in_array($generation->release_status, ['publish_failed', 'rolled_back'], true) ? 'err' : 'warn') }}">
                                {{ $generation->release_status }}
                            </span>
                            <div class="muted">{{ optional($generation->approved_at)->format('Y-m-d H:i:s') ?: 'not approved' }}</div>
                            <div class="muted">sign-off: {{ $currentSignoff?->status ?: 'n/a' }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $generation->last_smoke_check_status === 'ok' ? 'ok' : ($generation->last_smoke_check_status === 'failed' ? 'err' : 'warn') }}">
                                {{ $generation->last_smoke_check_status ?: 'n/a' }}
                            </span>
                            <div class="muted">{{ optional($generation->last_smoke_check_at)->format('Y-m-d H:i:s') ?: 'not checked' }}</div>
                        </td>
                        <td>
                            <span class="badge {{ ($guard['allowed'] ?? false) ? 'ok' : 'warn' }}">{{ ($guard['allowed'] ?? false) ? 'allowed' : 'blocked' }}</span>
                            @foreach(($guard['reasons'] ?? []) as $reason)
                                <div class="muted">{{ $reason }}</div>
                            @endforeach
                        </td>
                        <td>
                            <a class="button link" href="{{ route('admin.feed-profiles.acceptance.show', ['feed_profile' => $feedProfile, 'generation_id' => $generation->id]) }}">Acceptance</a>
                            <a class="button link" href="{{ route('admin.feed-profiles.generations.show', [$feedProfile, $generation]) }}">Details</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No generations yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px;">
            {{ $generations->links() }}
        </div>
    </section>

    <section class="panel">
        <h2>Audit Trail</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>Generation</th>
                    <th>User</th>
                    <th>Reason</th>
                </tr>
                </thead>
                <tbody>
                @forelse($recentReleaseEvents as $event)
                    <tr>
                        <td>{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $event->action }}</td>
                        <td>{{ $event->feedGeneration?->id ? '#'.$event->feedGeneration->id : 'n/a' }}</td>
                        <td>{{ $event->user?->email ?: 'system' }}</td>
                        <td>{{ $event->reason ?: 'n/a' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No release events yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
