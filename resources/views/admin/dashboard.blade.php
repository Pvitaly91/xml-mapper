@extends('layouts.admin', ['title' => 'Dashboard'])

@section('subtitle', 'Operational overview for the current shop.')

@section('content')
    <div class="stats">
        <div class="stat"><span class="muted">Source products</span><strong>{{ $metrics['total_source_products'] }}</strong></div>
        <div class="stat"><span class="muted">Source variants</span><strong>{{ $metrics['total_source_variants'] }}</strong></div>
        <div class="stat"><span class="muted">Feed items</span><strong>{{ $metrics['total_feed_items'] }}</strong></div>
        <div class="stat"><span class="muted">Ready</span><strong>{{ $metrics['ready_feed_items'] }}</strong></div>
        <div class="stat"><span class="muted">Invalid</span><strong>{{ $metrics['invalid_feed_items'] }}</strong></div>
        <div class="stat"><span class="muted">Excluded</span><strong>{{ $metrics['excluded_feed_items'] }}</strong></div>
        <div class="stat"><span class="muted">Active feed profiles</span><strong>{{ $metrics['active_feed_profiles'] }}</strong></div>
        <div class="stat"><span class="muted">Active validation errors</span><strong>{{ $metrics['active_validation_errors'] }}</strong></div>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Last Sync</h2>
            @if($metrics['last_sync'])
                <div class="detail-list">
                    <div class="detail-row"><strong>Status</strong><div>{{ $metrics['last_sync']->status }}</div></div>
                    <div class="detail-row"><strong>Finished at</strong><div>{{ optional($metrics['last_sync']->finished_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Offers total</strong><div>{{ $metrics['last_sync']->offers_total ?? 0 }}</div></div>
                </div>
            @else
                <p class="muted">No source imports yet.</p>
            @endif
        </section>
        <section class="panel">
            <h2>Last Build</h2>
            @if($metrics['last_build'])
                <div class="detail-list">
                    <div class="detail-row"><strong>Status</strong><div>{{ $metrics['last_build']->status }}</div></div>
                    <div class="detail-row"><strong>Built at</strong><div>{{ optional($metrics['last_build']->built_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Valid / invalid</strong><div>{{ $metrics['last_build']->valid_items_total }} / {{ $metrics['last_build']->invalid_items_total }}</div></div>
                </div>
            @else
                <p class="muted">No feed generations yet.</p>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Last Publish</h2>
            @if($metrics['last_publish'])
                <div class="detail-list">
                    <div class="detail-row"><strong>Status</strong><div>{{ $metrics['last_publish']->status }}</div></div>
                    <div class="detail-row"><strong>Published at</strong><div>{{ optional($metrics['last_publish']->published_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Published path</strong><div>{{ $metrics['last_publish']->published_path ?: 'n/a' }}</div></div>
                </div>
            @else
                <p class="muted">Nothing published yet.</p>
            @endif
        </section>
        <section class="panel">
            <h2>Quick Actions</h2>
            <div class="toolbar">
                <a class="button" href="{{ route('admin.source-connections.index') }}">Manage source connections</a>
                <a class="button secondary" href="{{ route('admin.feed-profiles.index') }}">Manage feed profiles</a>
                <a class="button secondary" href="{{ route('admin.dictionaries.index') }}">Import dictionaries</a>
            </div>
            <p class="muted">Shop: {{ $shop->name }} ({{ $shop->slug }})</p>
        </section>
    </div>
@endsection
