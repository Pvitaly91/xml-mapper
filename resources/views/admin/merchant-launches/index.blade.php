@extends('layouts.admin', ['title' => 'Launch Center'])

@section('subtitle', 'Live merchant launch execution, observations, defects, baseline deviations, stabilization, and handover.')

@section('safety_banner')
    <strong>One live launch record per merchant rollout</strong>
    Start the record only when production execution begins, then capture observations, defects, notifications, and closeout evidence in the same place.
@endsection

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a class="button secondary" href="{{ route('admin.pilot-runs.index') }}">Pilot center</a>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Start Live Launch</h2>
            <form method="POST" action="{{ route('admin.merchant-launches.store') }}" data-testid="launch-start-form">
                @csrf
                <div class="form-grid">
                    <div class="field full">
                        <label for="feed_profile_id">Feed profile</label>
                        <select id="feed_profile_id" name="feed_profile_id" required data-testid="launch-feed-profile">
                            @foreach($feedProfiles as $feedProfile)
                                <option value="{{ $feedProfile->id }}">
                                    {{ $feedProfile->name }} ({{ $feedProfile->code }}){{ $feedProfile->currentMerchantLaunch ? ' / active launch #'.$feedProfile->currentMerchantLaunch->id : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="pilot_run_id">Pilot run ID</label>
                        <input id="pilot_run_id" name="pilot_run_id" type="number" min="1" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label for="promotion_run_id">Promotion run ID</label>
                        <input id="promotion_run_id" name="promotion_run_id" type="number" min="1" placeholder="Optional">
                    </div>
                    <div class="field full">
                        <label for="launch_note">Note</label>
                        <textarea id="launch_note" name="note" placeholder="Production deploy context, merchant readiness note, or operator plan"></textarea>
                    </div>
                </div>
                <button class="button" type="submit" style="margin-top: 12px;" data-testid="launch-start-submit">Start launch record</button>
            </form>
        </section>

        <section class="panel">
            <h2>Operator Intent</h2>
            <ul>
                <li>Use one open launch record per feed profile during the first live merchant rollout.</li>
                <li>Capture merchant confirmation, pickup confirmation, anomalies, and false alarms as observations.</li>
                <li>Open structured defects when a live issue needs triage, mitigation, or follow-up.</li>
                <li>Use tuning only through safe settings paths and record the reason for every change.</li>
            </ul>
        </section>
    </div>

    <section class="panel">
        <h2>Launch Records</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Feed profile</th>
                    <th>State</th>
                    <th>Handover</th>
                    <th>Pilot</th>
                    <th>Published</th>
                    <th>Owner</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($launches as $launch)
                    <tr>
                        <td>#{{ $launch->id }}</td>
                        <td>{{ $launch->feedProfile?->name }}<br><span class="muted">{{ $launch->feedProfile?->code }}</span></td>
                        <td>{{ $launch->state }}</td>
                        <td>{{ $launch->handover_state }}</td>
                        <td>{{ $launch->pilotRun?->id ? '#'.$launch->pilotRun->id : 'n/a' }}</td>
                        <td>{{ $launch->publishedGeneration?->id ? '#'.$launch->publishedGeneration->id : 'n/a' }}</td>
                        <td>{{ $launch->owner?->email ?: 'unassigned' }}</td>
                        <td><a class="button link" href="{{ route('admin.merchant-launches.show', $launch) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No launch records yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px;">
            {{ $launches->links() }}
        </div>
    </section>
@endsection
