@extends('layouts.admin', ['title' => ($generation ? 'Acceptance For Generation #'.$generation->id : 'Acceptance Screen')])

@section('subtitle', 'Pilot review workflow: preview, QA bundle, sign-off, publish window, smoke checks, rollback readiness.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.release-center', $feed_profile) }}">Back to release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feed_profile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $feed_profile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reconciliation.show', $feed_profile) }}">Reconciliation</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feed_profile) }}">Rejection workbench</a>
            @if($generation)
                <a class="button secondary" href="{{ route('admin.feed-profiles.generations.show', [$feed_profile, $generation]) }}">Generation details</a>
                <a class="button secondary" href="{{ route('admin.feed-profiles.generations.qa-bundle', [$feed_profile, $generation]) }}">Download QA bundle</a>
                <a class="button secondary" href="{{ route('admin.feed-profiles.runbook.show', ['feed_profile' => $feed_profile, 'generation_id' => $generation->id]) }}">Download runbook</a>
            @endif
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Feed profile</strong><div>{{ $feed_profile->name }} ({{ $feed_profile->code }})</div></div>
            <div class="detail-row"><strong>Selected generation</strong><div>{{ $generation?->id ? '#'.$generation->id : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Release status</strong><div>{{ $generation?->release_status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Freeze mode</strong><div>{{ $publish_window['freeze_active'] ? 'active' : 'inactive' }}</div></div>
            <div class="detail-row"><strong>Publishing now</strong><div>{{ $publish_window['allowed_now'] ? 'allowed' : 'blocked' }}</div></div>
            <div class="detail-row"><strong>Next allowed window</strong><div>{{ $publish_window['next_allowed_at'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Sign-off status</strong><div>{{ $signoff['current']?->status ?: 'not recorded' }}</div></div>
            <div class="detail-row"><strong>Latest published smoke check</strong><div>{{ $latest_published_smoke_check?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Cutover status</strong><div>{{ $cutover['cutover']?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>First-pull verification</strong><div>{{ $first_pull_verification['latest']?->status ?: 'n/a' }}</div></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Go-Live Checklist</h2>
                <span class="badge {{ ($release_readiness['status'] ?? 'blocked') === 'ready' ? 'ok' : (($release_readiness['status'] ?? 'blocked') === 'blocked' ? 'err' : 'warn') }}">{{ $release_readiness['status'] ?? 'blocked' }}</span>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Onboarding completed</strong><div>{{ ($onboarding['completed'] ?? false) ? 'Yes' : 'No' }}</div></div>
                <div class="detail-row"><strong>Source healthy</strong><div>{{ ($release_readiness['checks']['source_healthy']['ok'] ?? false) ? 'Yes' : 'No' }}</div></div>
                <div class="detail-row"><strong>Last sync fresh</strong><div>{{ ($release_readiness['checks']['last_sync_fresh']['ok'] ?? false) ? 'Yes' : 'No' }}</div></div>
                <div class="detail-row"><strong>Unresolved mappings</strong><div>{{ $unresolved_mappings_count }}</div></div>
                <div class="detail-row"><strong>Ready / invalid / excluded</strong><div>{{ $pilot_readiness['generation_summary']['ready'] ?? 0 }} / {{ $pilot_readiness['generation_summary']['invalid_total'] ?? 0 }} / {{ $pilot_readiness['generation_summary']['excluded'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Dictionaries imported</strong><div>{{ $pilot_readiness['dictionaries_imported']['ok'] ? 'Yes' : 'No' }}</div></div>
                <div class="detail-row"><strong>Conformance blockers</strong><div>{{ ($release_readiness['checks']['critical_conformance']['count'] ?? 0) }}</div></div>
                <div class="detail-row"><strong>Publish guards</strong><div>{{ ($release_readiness['publish_guard']['allowed'] ?? false) ? 'pass' : 'blocked' }}</div></div>
                <div class="detail-row"><strong>Sign-off</strong><div>{{ $signoff['current']?->status ?: 'missing' }}</div></div>
                <div class="detail-row"><strong>Publish window</strong><div>{{ $publish_window['allowed_now'] ? 'open' : 'closed' }}</div></div>
                <div class="detail-row"><strong>Feedback open</strong><div>{{ $cutover['feedback_summary']['open_total'] ?? 0 }}</div></div>
            </div>

            @if(($release_readiness['blocking_issues'] ?? []) !== [])
                <h3 style="margin-top: 18px;">Blocking issues</h3>
                <ul>
                    @foreach($release_readiness['blocking_issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @endif

            @if(($release_readiness['warnings'] ?? []) !== [])
                <h3>Warnings</h3>
                <ul>
                    @foreach($release_readiness['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel">
            <h2>Direct Actions</h2>
            @if($generation)
                <div class="toolbar">
                    <form method="POST" action="{{ route('admin.feed-profiles.generations.preview-links.store', [$feed_profile, $generation]) }}">
                        @csrf
                        <input type="number" name="ttl_minutes" min="5" max="10080" value="1440">
                        <input type="text" name="reason" placeholder="Preview note">
                        <button class="button secondary" type="submit">Generate candidate preview link</button>
                    </form>
                    <form method="POST" action="{{ route('admin.feed-profiles.generations.approve', [$feed_profile, $generation]) }}">
                        @csrf
                        <input type="text" name="reason" placeholder="Approval note">
                        <button class="button secondary" type="submit">Approve candidate</button>
                    </form>
                    <form method="POST" action="{{ route('admin.feed-profiles.publish', $feed_profile) }}">
                        @csrf
                        <input type="hidden" name="generation_id" value="{{ $generation->id }}">
                        <button class="button" type="submit">Publish</button>
                    </form>
                    <form method="POST" action="{{ route('admin.feed-profiles.publish', $feed_profile) }}">
                        @csrf
                        <input type="hidden" name="generation_id" value="{{ $generation->id }}">
                        <input type="hidden" name="force_publish" value="1">
                        <input type="text" name="reason" placeholder="Emergency override reason" required>
                        <button class="button warning" type="submit">Force publish</button>
                    </form>
                    <form method="POST" action="{{ route('admin.feed-profiles.generations.smoke-check', [$feed_profile, $generation]) }}">
                        @csrf
                        <input type="text" name="reason" placeholder="Manual smoke-check note">
                        <button class="button secondary" type="submit">Rerun smoke check</button>
                    </form>
                    @if($feed_profile->publishedGeneration && $feed_profile->publishedGeneration->id === $generation->id)
                        <form method="POST" action="{{ route('admin.feed-profiles.generations.first-pull-verify', [$feed_profile, $generation]) }}">
                            @csrf
                            <input type="text" name="reason" placeholder="First-pull verification note">
                            <button class="button secondary" type="submit">Run first-pull verification</button>
                        </form>
                    @endif
                    @if($feed_profile->publishedGeneration && $feed_profile->publishedGeneration->id !== $generation->id)
                        <form method="POST" action="{{ route('admin.feed-profiles.rollback', $feed_profile) }}">
                            @csrf
                            <input type="hidden" name="to_generation_id" value="{{ $generation->id }}">
                            <input type="text" name="reason" placeholder="Rollback reason" required>
                            <button class="button danger" type="submit">Rollback</button>
                        </form>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('admin.feed-profiles.freeze', $feed_profile) }}" style="margin-top: 16px;">
                @csrf
                <input type="hidden" name="freeze" value="{{ $publish_window['freeze_active'] ? '0' : '1' }}">
                <input type="text" name="reason" placeholder="{{ $publish_window['freeze_active'] ? 'Reason to unfreeze' : 'Reason to freeze' }}" required>
                <button class="button warning" type="submit">{{ $publish_window['freeze_active'] ? 'Disable freeze' : 'Enable freeze' }}</button>
            </form>

            @if($generation)
                <form method="POST" action="{{ route('admin.feed-profiles.cutover', $feed_profile) }}" style="margin-top: 16px;">
                    @csrf
                    <input type="hidden" name="generation_id" value="{{ $generation->id }}">
                    <div class="form-grid">
                        <div class="field">
                            <label for="planned_window_starts_at">Cutover start</label>
                            <input id="planned_window_starts_at" type="datetime-local" name="planned_window_starts_at">
                        </div>
                        <div class="field">
                            <label for="planned_window_ends_at">Cutover end</label>
                            <input id="planned_window_ends_at" type="datetime-local" name="planned_window_ends_at">
                        </div>
                        <div class="field full">
                            <label for="cutover_note">Cutover note</label>
                            <input id="cutover_note" name="note" placeholder="Launch note">
                        </div>
                    </div>
                    <button class="button secondary" type="submit">Track production cutover</button>
                </form>
            @endif
        </section>
    </div>

    @if($generation)
        <div class="grid cols-2">
            <section class="panel">
                <h2>Preview Links</h2>
                @if($preview_links->isNotEmpty())
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($preview_links as $entry)
                                @php($previewLink = $entry['model'])
                                <tr>
                                    <td>#{{ $previewLink->id }}</td>
                                    <td>{{ optional($previewLink->expires_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                                    <td>{{ $previewLink->isActive() ? 'active' : 'inactive' }}</td>
                                    <td>
                                        @if($entry['url'])
                                            <a class="button link" href="{{ $entry['url'] }}" target="_blank" rel="noreferrer">Open preview</a>
                                        @endif
                                        <form method="POST" action="{{ route('admin.feed-profiles.generations.preview-links.smoke-check', [$feed_profile, $generation, $previewLink]) }}" style="display: inline-flex;">
                                            @csrf
                                            <input type="text" name="reason" placeholder="Preview smoke-check note">
                                            <button class="button secondary" type="submit">Smoke check</button>
                                        </form>
                                        @if($previewLink->revoked_at === null)
                                            <form method="POST" action="{{ route('admin.feed-profiles.generations.preview-links.revoke', [$feed_profile, $generation, $previewLink]) }}" style="display: inline-flex;">
                                                @csrf
                                                <input type="text" name="reason" placeholder="Revoke reason">
                                                <button class="button warning" type="submit">Revoke</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="muted">No preview links yet.</p>
                @endif
            </section>

            <section class="panel">
                <h2>Review And Notes</h2>
                <form method="POST" action="{{ route('admin.feed-profiles.generations.signoff', [$feed_profile, $generation]) }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field">
                            <label for="signoff_status">Sign-off status</label>
                            <select id="signoff_status" name="status">
                                @foreach(['pending_review', 'internal_approved', 'client_review', 'client_approved', 'rejected'] as $status)
                                    <option value="{{ $status }}">{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="reviewer_name">Reviewer name</label>
                            <input id="reviewer_name" name="reviewer_name">
                        </div>
                        <div class="field full">
                            <label for="signoff_note">Note</label>
                            <input id="signoff_note" name="note">
                        </div>
                        <div class="field full">
                            <label for="signoff_reason">Reason</label>
                            <input id="signoff_reason" name="reason">
                        </div>
                    </div>
                    <button class="button secondary" type="submit">Request or record sign-off</button>
                </form>

                <form method="POST" action="{{ route('admin.feed-profiles.generations.notes', [$feed_profile, $generation]) }}" style="margin-top: 16px;">
                    @csrf
                    <div class="form-grid">
                        <div class="field">
                            <label for="note_type">Note type</label>
                            <select id="note_type" name="note_type">
                                <option value="internal">internal</option>
                                <option value="external">external</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="check" style="margin-top: 28px;"><input type="checkbox" name="important" value="1"> Include in QA bundle notes</label>
                        </div>
                        <div class="field full">
                            <label for="note_body">Note</label>
                            <textarea id="note_body" name="body"></textarea>
                        </div>
                    </div>
                    <button class="button secondary" type="submit">Save note</button>
                </form>

                @if($notes->isNotEmpty())
                    <h3 style="margin-top: 18px;">Recent notes</h3>
                    <ul>
                        @foreach($notes as $note)
                            <li>
                                <strong>{{ $note->meta['note_type'] ?? 'internal' }}</strong>:
                                {{ $note->meta['body'] ?? $note->reason }}
                                @if($note->meta['important'] ?? false)
                                    (included in QA bundle)
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif

    <section class="panel">
        <h2>Cutover And Feedback</h2>
        <div class="detail-list">
            <div class="detail-row"><strong>Cutover status</strong><div>{{ $cutover['cutover']?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Accepted feedback</strong><div>{{ $cutover['feedback_summary']['accepted_total'] ?? 0 }}</div></div>
            <div class="detail-row"><strong>Rejected feedback</strong><div>{{ $cutover['feedback_summary']['rejected_total'] ?? 0 }}</div></div>
            <div class="detail-row"><strong>Warnings</strong><div>{{ $cutover['feedback_summary']['warning_total'] ?? 0 }}</div></div>
            <div class="detail-row"><strong>Unmatched feedback</strong><div>{{ $cutover['feedback_summary']['unmatched_total'] ?? 0 }}</div></div>
        </div>

        <div class="toolbar" style="margin-top: 16px;">
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback.create', $feed_profile) }}">Import feedback</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feed_profile) }}">Open rejection workbench</a>
        </div>
    </section>
@endsection
