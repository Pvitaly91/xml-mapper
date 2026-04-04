@extends('layouts.admin', ['title' => $connection->name])

@section('subtitle', 'Operational state, recent imports and related feed profiles.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button link" href="{{ route('admin.source-connections.edit', $connection) }}">Edit</a>
            <form method="POST" action="{{ route('admin.source-connections.test', $connection) }}">
                @csrf
                <button class="button secondary" type="submit">Test connection</button>
            </form>
            <form method="POST" action="{{ route('admin.source-connections.sync', $connection) }}">
                @csrf
                <button class="button" type="submit">Sync now</button>
            </form>
            @if($connection->driver === \App\Models\SourceConnection::DRIVER_PROM_API)
                <form method="POST" action="{{ route('admin.source-connections.rotation', $connection) }}">
                    @csrf
                    <input type="hidden" name="target" value="prom_api_token">
                    <input type="text" name="note" placeholder="Rotation note">
                    <button class="button warning" type="submit">Record token rotation</button>
                </form>
            @endif
            <a class="button secondary" href="{{ route('admin.source-connections.index') }}">Back</a>
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Code</strong><div>{{ $connection->code }}</div></div>
            <div class="detail-row"><strong>Driver</strong><div>{{ $connection->driver }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $connection->status }}</div></div>
            <div class="detail-row"><strong>Source URL</strong><div>{{ $connection->source_url ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>API base URL</strong><div>{{ $connection->driver === \App\Models\SourceConnection::DRIVER_PROM_API ? $connection->resolvedApiBaseUrl() : 'n/a' }}</div></div>
            <div class="detail-row"><strong>API version</strong><div>{{ $connection->driver === \App\Models\SourceConnection::DRIVER_PROM_API ? $connection->resolvedApiVersion() : 'n/a' }}</div></div>
            <div class="detail-row"><strong>API token</strong><div>{{ $connection->driver === \App\Models\SourceConnection::DRIVER_PROM_API ? ($connection->maskedApiToken() ?: 'not configured') : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Credentials bundle</strong><div>{{ $connection->driver === \App\Models\SourceConnection::DRIVER_PROM_YML ? (!empty($connection->credentials) ? 'configured (masked)' : 'not configured') : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Promotion secret state</strong><div>{{ $connection->promotionSecretState() }}</div></div>
            <div class="detail-row"><strong>Promotion rebind required</strong><div>{{ $connection->promotionSecretRebindRequired() ? 'yes' : 'no' }}</div></div>
            <div class="detail-row"><strong>Token present</strong><div>{{ ($rotation['token_present'] ?? false) ? 'yes' : 'no' }}</div></div>
            <div class="detail-row"><strong>Token last validated</strong><div>{{ optional($rotation['token_last_validated_at'] ?? null)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Last rotation</strong><div>{{ optional($rotation['latest_rotation']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Sync interval</strong><div>{{ $connection->sync_interval_minutes }} minutes</div></div>
            <div class="detail-row"><strong>Last sync at</strong><div>{{ optional($connection->last_synced_at)->format('Y-m-d H:i:s') ?: 'Never' }}</div></div>
            <div class="detail-row"><strong>Next sync at</strong><div>{{ optional($connection->next_sync_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Last connection check</strong><div>{{ optional($connection->last_connection_check_at)->format('Y-m-d H:i:s') ?: 'Never' }} / {{ $connection->last_connection_check_status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Check message</strong><div>{{ $connection->last_connection_check_message ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Last sync status</strong><div>{{ $connection->last_sync_status ?: ($connection->latestImport?->status ?: 'n/a') }}</div></div>
            <div class="detail-row"><strong>Last sync message</strong><div>{{ $connection->last_sync_message ?: ($connection->latestImport?->error_message ?: 'n/a') }}</div></div>
            <div class="detail-row"><strong>Import summary</strong><div>
                @if($connection->last_sync_summary)
                    {{ $connection->last_sync_summary['categories'] ?? 0 }} categories /
                    {{ $connection->last_sync_summary['products'] ?? 0 }} products /
                    {{ $connection->last_sync_summary['variants'] ?? 0 }} variants
                @else
                    n/a
                @endif
            </div></div>
        </div>
        @if($connection->promotionMeta() !== [])
            <div class="detail-list" style="margin-top: 18px;">
                <div class="detail-row"><strong>Promotion snapshot checksum</strong><div>{{ $connection->promotionMeta()['source_snapshot_checksum'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>Required secret fields</strong><div>{{ implode(', ', $connection->promotionMeta()['secret_policy']['required_fields'] ?? []) ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Promotion applied at</strong><div>{{ $connection->promotionMeta()['applied_at'] ?? 'n/a' }}</div></div>
                <div class="detail-row"><strong>Promotion validated at</strong><div>{{ $connection->promotionMeta()['validated_at'] ?? 'n/a' }}</div></div>
            </div>
        @endif
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
                <thead><tr><th>ID</th><th>Status</th><th>Started</th><th>Finished</th><th>Categories / offers</th><th>Error</th></tr></thead>
                <tbody>
                @forelse($imports as $import)
                    <tr>
                        <td>#{{ $import->id }}</td>
                        <td>{{ $import->status }}</td>
                        <td>{{ optional($import->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ optional($import->finished_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $import->categories_total ?? 0 }} / {{ $import->offers_total ?? 0 }}</td>
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
