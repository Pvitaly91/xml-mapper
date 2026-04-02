@extends('layouts.admin', ['title' => 'Source Connections'])

@section('subtitle', 'Configure Prom source feeds, inspect sync status and run manual sync.')

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
                    <option value="prom_yml" @selected(($filters['driver'] ?? '') === 'prom_yml')>prom_yml</option>
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
                            <span class="muted">{{ $connection->source_url ?: 'No URL configured' }}</span>
                        </td>
                        <td><span class="badge">{{ $connection->driver }}</span></td>
                        <td>{{ optional($connection->last_synced_at)->format('Y-m-d H:i:s') ?: 'Never' }}</td>
                        <td>{{ optional($connection->next_sync_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            @if($connection->latestImport)
                                <span class="badge {{ $connection->latestImport->status === 'failed' ? 'err' : 'ok' }}">{{ $connection->latestImport->status }}</span>
                            @else
                                <span class="muted">No imports</span>
                            @endif
                        </td>
                        <td>
                            <div class="toolbar">
                                <a class="button link" href="{{ route('admin.source-connections.show', $connection) }}">Show</a>
                                <a class="button link" href="{{ route('admin.source-connections.edit', $connection) }}">Edit</a>
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
