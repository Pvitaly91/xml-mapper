@extends('layouts.admin', ['title' => 'Pilot Run #'.$pilotRun->id])

@section('subtitle', 'Operator execution screen for rehearsal, promotion, source verification, publish, feedback remediation, hypercare, and evidence.')

@section('content')
    @php($feedProfile = $pilotRun->feedProfile)
    @php($sections = $pilotRun->summary['sections'] ?? [])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.pilot-runs.index') }}">Back to Pilot Center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Feed profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.rehearsal.show', $feedProfile) }}">Open rehearsal</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.promotion.show', $feedProfile) }}">Open promotion</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Open release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Open feedback remediation</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.show', $feedProfile) }}">Open hypercare</a>
            <a class="button secondary" href="{{ route('admin.pilot-runs.evidence', $pilotRun) }}">Download evidence pack</a>
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">State</span><strong>{{ $pilotRun->state }}</strong></div>
            <div class="stat"><span class="muted">Current step</span><strong>{{ data_get($pilotRun->summary, 'execution.current_step_label', $pilotRun->current_step ?: 'n/a') }}</strong></div>
            <div class="stat"><span class="muted">Next step</span><strong>{{ data_get($pilotRun->summary, 'execution.next_step_label', 'n/a') }}</strong></div>
            <div class="stat"><span class="muted">Readiness</span><strong>{{ $readiness['status'] ?? 'not_ready' }}</strong></div>
            <div class="stat"><span class="muted">Pilot score</span><strong>{{ $readiness['score'] ?? 0 }}</strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Execution Summary</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Feed profile</strong><div>{{ $feedProfile->name }} ({{ $feedProfile->code }})</div></div>
                <div class="detail-row"><strong>Owner</strong><div>{{ $pilotRun->owner?->email ?: 'unassigned' }}</div></div>
                <div class="detail-row"><strong>Initiated by</strong><div>{{ $pilotRun->initiatedBy?->email ?: 'system' }}</div></div>
                <div class="detail-row"><strong>Environment</strong><div>{{ $pilotRun->environment_label ?: $pilotRun->environment_class }}</div></div>
                <div class="detail-row"><strong>Started</strong><div>{{ optional($pilotRun->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Finished</strong><div>{{ optional($pilotRun->finished_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Source snapshot</strong><div>{{ $pilotRun->sourceSnapshot?->id ? '#'.$pilotRun->sourceSnapshot->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Candidate generation</strong><div>{{ $pilotRun->candidateGeneration?->id ? '#'.$pilotRun->candidateGeneration->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Published generation</strong><div>{{ $pilotRun->publishedGeneration?->id ? '#'.$pilotRun->publishedGeneration->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Blocking reason</strong><div>{{ $pilotRun->blocking_reason ?: 'none' }}</div></div>
                <div class="detail-row"><strong>Note</strong><div>{{ $pilotRun->note ?: 'n/a' }}</div></div>
            </div>
        </section>

        <section class="panel">
            <h2>Readiness / Rules</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Status</strong><div>{{ $readiness['status'] ?? 'not_ready' }}</div></div>
                <div class="detail-row"><strong>Score</strong><div>{{ $readiness['score'] ?? 0 }}/100</div></div>
                <div class="detail-row"><strong>Fingerprint</strong><div><code>{{ $readiness['fingerprint'] ?? 'n/a' }}</code></div></div>
                <div class="detail-row"><strong>Resume allowed</strong><div>{{ data_get($pilotRun->summary, 'resume.allowed') ? 'yes' : 'no' }}</div></div>
                <div class="detail-row"><strong>Abort allowed</strong><div>{{ data_get($pilotRun->summary, 'abort.allowed') ? 'yes' : 'no' }}</div></div>
            </div>

            @if(($readiness['blocking_reasons'] ?? []) !== [])
                <h3>Blocking reasons</h3>
                <ul>
                    @foreach($readiness['blocking_reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif

            @if(($readiness['warnings'] ?? []) !== [])
                <h3>Warnings</h3>
                <ul>
                    @foreach($readiness['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif

            @if($blocker)
                <h3>Operator-visible next steps</h3>
                <ul>
                    @foreach(($blocker['next_steps'] ?? []) as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            @elseif($nextStep['label'] ?? false)
                <p class="muted">Next step: {{ $nextStep['label'] }}</p>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Quick Actions</h2>
            <form method="POST" action="{{ route('admin.pilot-runs.next', $pilotRun) }}">
                @csrf
                <div class="checks">
                    <label class="check"><input type="checkbox" name="with_sync" value="1"> With sync</label>
                    <label class="check"><input type="checkbox" name="with_build" value="1"> Force build</label>
                    <label class="check"><input type="checkbox" name="with_publish" value="1"> Continue publish</label>
                    <label class="check"><input type="checkbox" name="with_feedback_fixtures" value="1"> Import feedback fixtures</label>
                </div>
                <button class="button" type="submit" style="margin-top: 12px;">Run next step</button>
            </form>

            <form method="POST" action="{{ route('admin.pilot-runs.resume', $pilotRun) }}" style="margin-top: 16px;">
                @csrf
                <div class="checks">
                    <label class="check"><input type="checkbox" name="with_sync" value="1"> With sync</label>
                    <label class="check"><input type="checkbox" name="with_build" value="1"> Force build</label>
                    <label class="check"><input type="checkbox" name="with_publish" value="1"> Continue publish</label>
                    <label class="check"><input type="checkbox" name="with_feedback_fixtures" value="1"> Import feedback fixtures</label>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Resume run</button>
            </form>

            <form method="POST" action="{{ route('admin.pilot-runs.abort', $pilotRun) }}" style="margin-top: 16px;">
                @csrf
                <div class="field">
                    <label for="abort_reason">Abort reason</label>
                    <input id="abort_reason" type="text" name="reason" required placeholder="Why the pilot must stop">
                </div>
                <button class="button danger" type="submit" style="margin-top: 12px;">Abort run</button>
            </form>
        </section>

        <section class="panel">
            <h2>Notes / Incidents / Overrides</h2>
            <form method="POST" action="{{ route('admin.pilot-runs.events.store', $pilotRun) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="event_type">Event type</label>
                        <select id="event_type" name="event_type">
                            <option value="note">Note</option>
                            <option value="incident">Incident</option>
                            <option value="override">Override</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label for="event_message">Message</label>
                        <textarea id="event_message" name="message" required placeholder="Operator evidence, decision, blocker, workaround, or override detail"></textarea>
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;">Record event</button>
            </form>

            <h3 style="margin-top: 18px;">Reports</h3>
            <div class="toolbar">
                @foreach($reportTypes as $type => $label)
                    <a class="button link" href="{{ route('admin.pilot-runs.reports.show', [$pilotRun, $type]) }}">{{ $label }}</a>
                @endforeach
            </div>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Operational Sections</h2>
            <pre>{{ json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre>
        </section>

        <section class="panel">
            <h2>Readiness Components</h2>
            <pre>{{ json_encode($readiness['components'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre>
        </section>
    </div>

    <section class="panel">
        <h2>Execution History</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>When</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Step</th>
                    <th>Transition</th>
                    <th>User</th>
                    <th>Message</th>
                </tr>
                </thead>
                <tbody>
                @forelse($history as $event)
                    <tr>
                        <td>{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $event->event_type }}</td>
                        <td>{{ $event->status }}</td>
                        <td>{{ $event->step ?: 'n/a' }}</td>
                        <td>{{ ($event->from_state ?: 'n/a').' -> '.($event->to_state ?: 'n/a') }}</td>
                        <td>{{ $event->user?->email ?: 'system' }}</td>
                        <td>
                            <strong>{{ $event->title }}</strong><br>
                            <span class="muted">{{ $event->message ?: 'n/a' }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No pilot events yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px;">
            {{ $history->links() }}
        </div>
    </section>
@endsection
