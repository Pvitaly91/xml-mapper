@extends('layouts.admin', ['title' => $shop->name.' Pilot Center'])

@section('subtitle', 'Persisted merchant pilot execution runs, readiness score, blockers, evidence, and resume control.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.shop-control.show') }}">Go-Live Control</a>
            <a class="button secondary" href="{{ route('admin.onboarding.show') }}">Onboarding</a>
        </div>

        <form method="POST" action="{{ route('admin.pilot-runs.store') }}">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="pilot_feed_profile_id">Feed profile</label>
                    <select id="pilot_feed_profile_id" name="feed_profile_id" required>
                        <option value="">Select feed profile</option>
                        @foreach($feedProfiles as $feedProfile)
                            <option value="{{ $feedProfile->id }}">{{ $feedProfile->name }} ({{ $feedProfile->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field full">
                    <label for="pilot_note">Operator note</label>
                    <textarea id="pilot_note" name="note" placeholder="Pilot intent, merchant context, constraints, or handoff note"></textarea>
                </div>
            </div>
            <button class="button" type="submit" style="margin-top: 12px;">Plan pilot run</button>
        </form>
    </section>

    <section class="panel">
        <h2>Pilot Runs</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Feed profile</th>
                    <th>State</th>
                    <th>Next step</th>
                    <th>Owner</th>
                    <th>Readiness</th>
                    <th>Started</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($runs as $run)
                    @php($readiness = data_get($run->summary, 'readiness', []))
                    <tr>
                        <td>#{{ $run->id }}</td>
                        <td>
                            <strong>{{ $run->feedProfile?->name ?: 'n/a' }}</strong><br>
                            <span class="muted">{{ $run->feedProfile?->code ?: 'n/a' }}</span>
                        </td>
                        <td>
                            <span class="badge {{ in_array($run->state, ['completed', 'published', 'first_pull_verified', 'hypercare_active'], true) ? 'ok' : (in_array($run->state, ['blocked', 'failed', 'aborted'], true) ? 'err' : 'warn') }}">
                                {{ $run->state }}
                            </span>
                        </td>
                        <td>{{ data_get($run->summary, 'execution.next_step_label', 'n/a') }}</td>
                        <td>{{ $run->owner?->email ?: 'unassigned' }}</td>
                        <td>
                            <span class="badge {{ ($readiness['status'] ?? 'not_ready') === 'stable_after_launch' || ($readiness['status'] ?? 'not_ready') === 'ready' ? 'ok' : (($readiness['status'] ?? 'not_ready') === 'needs_attention' ? 'warn' : 'err') }}">
                                {{ $readiness['status'] ?? 'not_ready' }}
                            </span>
                            <div class="muted">{{ $readiness['score'] ?? 0 }}/100</div>
                        </td>
                        <td>{{ optional($run->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            <a class="button link" href="{{ route('admin.pilot-runs.show', $run) }}">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No pilot runs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px;">
            {{ $runs->links() }}
        </div>
    </section>
@endsection
