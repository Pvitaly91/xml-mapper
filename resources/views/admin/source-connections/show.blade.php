@extends('layouts.admin', ['title' => $connection->name])

@section('subtitle', 'Operational state, recent imports and related feed profiles.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button link" href="{{ route('admin.source-connections.edit', $connection) }}">Edit</a>
            <form method="POST" action="{{ route('admin.source-connections.sync', $connection) }}">
                @csrf
                <button class="button" type="submit">Sync now</button>
            </form>
            <a class="button secondary" href="{{ route('admin.source-connections.index') }}">Back</a>
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Code</strong><div>{{ $connection->code }}</div></div>
            <div class="detail-row"><strong>Driver</strong><div>{{ $connection->driver }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $connection->status }}</div></div>
            <div class="detail-row"><strong>Source URL</strong><div>{{ $connection->source_url ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Sync interval</strong><div>{{ $connection->sync_interval_minutes }} minutes</div></div>
            <div class="detail-row"><strong>Last sync at</strong><div>{{ optional($connection->last_synced_at)->format('Y-m-d H:i:s') ?: 'Never' }}</div></div>
            <div class="detail-row"><strong>Next sync at</strong><div>{{ optional($connection->next_sync_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Last sync status</strong><div>{{ $connection->latestImport?->status ?: 'n/a' }}</div></div>
        </div>
    </section>

    <section class="panel">
        <h2>Related Feed Profiles</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Status</th><th>Auto build</th><th></th></tr></thead>
                <tbody>
                @forelse($connection->feedProfiles as $profile)
                    <tr>
                        <td>{{ $profile->name }}</td>
                        <td>{{ $profile->status }}</td>
                        <td>{{ $profile->auto_build ? 'Yes' : 'No' }}</td>
                        <td><a class="button link" href="{{ route('admin.feed-profiles.show', $profile) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No feed profiles use this source yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Recent Imports</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Status</th><th>Started</th><th>Finished</th><th>Offers</th><th>Error</th></tr></thead>
                <tbody>
                @forelse($imports as $import)
                    <tr>
                        <td>#{{ $import->id }}</td>
                        <td>{{ $import->status }}</td>
                        <td>{{ optional($import->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ optional($import->finished_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $import->offers_total ?? 0 }}</td>
                        <td>{{ $import->error_message ?: 'n/a' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No imports yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('components.admin.paginator', ['paginator' => $imports])
    </section>
@endsection
