@extends('layouts.admin', ['title' => 'Feed Profiles'])

@section('subtitle', 'Manage public feed settings, manual builds and publication workflow.')

@section('content')
    <section class="panel">
        <div class="toolbar" style="justify-content: space-between;">
            <a class="button" href="{{ route('admin.feed-profiles.create') }}">Create feed profile</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="search">Search</label>
                <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="name or code">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Any</option>
                    <option value="draft" @selected(($filters['status'] ?? '') === 'draft')>Draft</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="field">
                <label for="source_connection_id">Source connection</label>
                <select id="source_connection_id" name="source_connection_id">
                    <option value="">Any</option>
                    @foreach($sourceConnections as $connection)
                        <option value="{{ $connection->id }}" @selected((string) ($filters['source_connection_id'] ?? '') === (string) $connection->id)>{{ $connection->name }}</option>
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
                    <th>Profile</th>
                    <th>Source</th>
                    <th>Last build</th>
                    <th>Published</th>
                    <th>Public URL</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($profiles as $profile)
                    <tr>
                        <td>
                            <strong>{{ $profile->name }}</strong><br>
                            <span class="muted">{{ $profile->code }}</span><br>
                            <span class="badge {{ $profile->status === 'active' ? 'ok' : 'warn' }}">{{ $profile->status }}</span>
                        </td>
                        <td>{{ $profile->sourceConnection?->name ?: 'n/a' }}</td>
                        <td>
                            @if($profile->latestGeneration)
                                <span class="badge">{{ $profile->latestGeneration->status }}</span><br>
                                <span class="muted">{{ optional($profile->latestGeneration->built_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</span>
                            @else
                                <span class="muted">No builds</span>
                            @endif
                        </td>
                        <td>
                            @if($profile->publishedGeneration)
                                <span class="badge ok">published</span><br>
                                <span class="muted">{{ optional($profile->publishedGeneration->published_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</span>
                            @else
                                <span class="muted">Not published</span>
                            @endif
                        </td>
                        <td>
                            @if($profile->published_path)
                                <a href="{{ route('feeds.public', $profile->public_token) }}" target="_blank" class="button link">Open feed</a>
                            @else
                                <span class="muted">n/a</span>
                            @endif
                        </td>
                        <td>
                            <div class="toolbar">
                                <a class="button link" href="{{ route('admin.feed-profiles.show', $profile) }}">Show</a>
                                <a class="button link" href="{{ route('admin.feed-profiles.edit', $profile) }}">Edit</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No feed profiles found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @include('components.admin.paginator', ['paginator' => $profiles])
    </section>
@endsection
