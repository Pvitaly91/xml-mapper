@extends('layouts.admin', ['title' => 'Source Connections'])

@section('subtitle', 'Configure Prom YML / Prom API sources, inspect connectivity and run manual sync.')

@section('content')
    <section class="panel">
        <div class="toolbar" style="justify-content: space-between;">
            <a class="button" href="{{ route('admin.source-connections.create') }}">Create source connection</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="search">Search</label>
                <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="name, code, source URL">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Any</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="paused" @selected(($filters['status'] ?? '') === 'paused')>Paused</option>
                </select>
            </div>
            <div class="field">
                <label for="driver">Driver</label>
                <select id="driver" name="driver">
                    <option value="">Any</option>
                    @foreach(\App\Models\SourceConnection::driverOptions() as $driver => $label)
                        <option value="{{ $driver }}" @selected(($filters['driver'] ?? '') === $driver)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="align-self: end;">
                <button class="button secondary" type="submit">Apply filters</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Driver</th>
                    <th>Last sync</th>
                    <th>Next sync</th>
                    <th>Last status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($connections as $connection)
                    <tr>
                        <td>
                            <strong>{{ $connection->name }}</strong><br>
                            <span class="muted">{{ $connection->code }}</span><br>
                            <span class="muted">
                                @if($connection->driver === \App\Models\SourceConnection::DRIVER_PROM_API)
                                    {{ $connection->resolvedApiBaseUrl() }}/api/{{ $connection->resolvedApiVersion() }}
                                @else
                                    {{ $connection->source_url ?: 'No URL configured' }}
                                @endif
                            </span>
                        </td>
                        <td><span class="badge">{{ $connection->driver }}</span></td>
                        <td>{{ optional($connection->last_synced_at)->format('Y-m-d H:i:s') ?: 'Never' }}</td>
                        <td>{{ optional($connection->next_sync_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            <div>
                                <span class="badge {{ $connection->last_connection_check_status === 'ok' ? 'ok' : ($connection->last_connection_check_status ? 'err' : '') }}">
                                    check: {{ $connection->last_connection_check_status ?: 'n/a' }}
                                </span>
                            </div>
                            <div style="margin-top: 6px;">
                                <span class="badge {{ $connection->last_sync_status === 'ok' ? 'ok' : ($connection->last_sync_status ? 'err' : '') }}">
                                    sync: {{ $connection->last_sync_status ?: ($connection->latestImport?->status ?: 'n/a') }}
                                </span>
                            </div>
                            @if($connection->last_sync_summary)
                                <div class="muted" style="margin-top: 6px;">
                                    {{ $connection->last_sync_summary['categories'] ?? 0 }} categories /
                                    {{ $connection->last_sync_summary['products'] ?? 0 }} products /
                                    {{ $connection->last_sync_summary['variants'] ?? 0 }} variants
                                </div>
                            @elseif($connection->latestImport)
                                <div class="muted" style="margin-top: 6px;">
                                    {{ $connection->latestImport->categories_total ?? 0 }} categories /
                                    {{ $connection->latestImport->offers_total ?? 0 }} offers
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="toolbar">
                                <a class="button link" href="{{ route('admin.source-connections.show', $connection) }}">Show</a>
                                <a class="button link" href="{{ route('admin.source-connections.edit', $connection) }}">Edit</a>
                                <form method="POST" action="{{ route('admin.source-connections.test', $connection) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">Test connection</button>
                                </form>
                                <form method="POST" action="{{ route('admin.source-connections.sync', $connection) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">Sync now</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No source connections found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @include('components.admin.paginator', ['paginator' => $connections])
    </section>
@endsection
