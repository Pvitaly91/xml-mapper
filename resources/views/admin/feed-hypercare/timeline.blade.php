@extends('layouts.admin', ['title' => $feedProfile->name.' Live Timeline'])

@section('subtitle', 'Unified post-launch timeline across release, sync, smoke, verification, and ops events.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.hypercare.show', $feedProfile) }}">Back to war room</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.timeline.download', array_merge(['feed_profile' => $feedProfile], $filters)) }}">Download CSV</a>
        </div>

        <form method="GET" action="{{ route('admin.feed-profiles.hypercare.timeline.show', $feedProfile) }}">
            <div class="filters">
                <div class="field">
                    <label for="event_type">Event type</label>
                    <select id="event_type" name="event_type">
                        <option value="">All</option>
                        @foreach(['release_event', 'sync_log', 'smoke_check', 'first_pull', 'ops_run'] as $type)
                            <option value="{{ $type }}" @selected(($filters['event_type'] ?? '') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="severity">Severity</label>
                    <select id="severity" name="severity">
                        <option value="">All</option>
                        @foreach(['info', 'warning', 'critical'] as $severity)
                            <option value="{{ $severity }}" @selected(($filters['severity'] ?? '') === $severity)>{{ $severity }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="from">From</label>
                    <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
                </div>
                <div class="field">
                    <label for="to">To</label>
                    <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
                </div>
            </div>
            <button class="button secondary" type="submit">Apply filters</button>
        </form>
    </section>

    <section class="panel">
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Type</th><th>Severity</th><th>Actor</th><th>Title</th><th>Message</th></tr></thead>
                <tbody>
                @forelse($timeline as $event)
                    <tr>
                        <td>{{ $event['occurred_at'] }}</td>
                        <td>{{ $event['event_type'] }}</td>
                        <td>{{ $event['severity'] }}</td>
                        <td>{{ $event['actor'] }}</td>
                        <td>{{ $event['title'] }}</td>
                        <td>{{ $event['message'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No timeline events match the selected filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
