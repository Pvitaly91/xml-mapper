@extends('layouts.admin', ['title' => $feedProfile->name])

@section('subtitle', 'Manual build/publish workflow and drill-down into mappings and feed items.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button link" href="{{ route('admin.feed-profiles.edit', $feedProfile) }}">Edit</a>
            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}">
                @csrf
                <button class="button" type="submit">Build now</button>
            </form>
            <form method="POST" action="{{ route('admin.feed-profiles.publish', $feedProfile) }}">
                @csrf
                <button class="button secondary" type="submit">Publish latest</button>
            </form>
            <form method="POST" action="{{ route('admin.feed-profiles.status', $feedProfile) }}">
                @csrf
                <input type="hidden" name="status" value="{{ $feedProfile->status === 'active' ? 'inactive' : 'active' }}">
                <button class="button warning" type="submit">{{ $feedProfile->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
            </form>
            <a class="button secondary" href="{{ route('admin.feed-profiles.index') }}">Back</a>
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Code</strong><div>{{ $feedProfile->code }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $feedProfile->status }}</div></div>
            <div class="detail-row"><strong>Source connection</strong><div>{{ $feedProfile->sourceConnection?->name ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Include unavailable</strong><div>{{ $feedProfile->include_unavailable ? 'Yes' : 'No' }}</div></div>
            <div class="detail-row"><strong>Auto sync / auto build</strong><div>{{ $feedProfile->auto_sync ? 'Yes' : 'No' }} / {{ $feedProfile->auto_build ? 'Yes' : 'No' }}</div></div>
            <div class="detail-row"><strong>Last build status</strong><div>{{ $feedProfile->latestGeneration?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Published generation</strong><div>{{ $feedProfile->publishedGeneration?->id ? '#'.$feedProfile->publishedGeneration->id : 'n/a' }}</div></div>
            <div class="detail-row"><strong>Public feed URL</strong><div>{{ $publicFeedUrl ?: 'n/a' }}</div></div>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Feed items</span><strong>{{ $feedItemStats['total'] }}</strong></div>
        <div class="stat"><span class="muted">Ready</span><strong>{{ $feedItemStats['ready'] }}</strong></div>
        <div class="stat"><span class="muted">Invalid</span><strong>{{ $feedItemStats['invalid'] }}</strong></div>
        <div class="stat"><span class="muted">Excluded</span><strong>{{ $feedItemStats['excluded'] }}</strong></div>
        <div class="stat"><span class="muted">Active validation errors</span><strong>{{ $activeValidationErrors }}</strong></div>
    </div>

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.category-mappings.index', $feedProfile) }}">Category mappings</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.attribute-mappings.index', $feedProfile) }}">Attribute mappings</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.value-mappings.index', $feedProfile) }}">Value mappings</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feed-items.index', $feedProfile) }}">Feed items</a>
        </div>
    </section>

    <section class="panel">
        <h2>Recent Generations</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Status</th><th>Built</th><th>Published</th><th>Items</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($recentGenerations as $generation)
                    <tr>
                        <td>#{{ $generation->id }}</td>
                        <td>{{ $generation->status }}</td>
                        <td>{{ optional($generation->built_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ optional($generation->published_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $generation->valid_items_total }} / {{ $generation->invalid_items_total }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.feed-profiles.publish', $feedProfile) }}">
                                @csrf
                                <input type="hidden" name="generation_id" value="{{ $generation->id }}">
                                <button class="button link" type="submit">Publish this</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No generations yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
