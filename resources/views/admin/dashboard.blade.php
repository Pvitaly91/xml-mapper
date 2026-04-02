@extends('layouts.admin', ['title' => 'Dashboard'])

@section('subtitle', 'Operational overview for the current shop.')

@section('content')
    @php
        $badgeClass = fn (string $status) => match ($status) {
            'ok' => 'ok',
            'setup_required' => 'warn',
            'not_applicable' => '',
            default => 'err',
        };
    @endphp
    @if($metrics['setup_required'])
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Database Setup Required</h2>
                <span class="badge warn">setup_required</span>
            </div>
            <p class="muted">
                The database schema is not fully initialized yet, so dashboard metrics are paused until the missing tables are created.
            </p>
            @if($metrics['missing_tables'])
                <div class="detail-list" style="margin-top: 14px;">
                    <div class="detail-row">
                        <strong>Missing tables</strong>
                        <div>{{ implode(', ', $metrics['missing_tables']) }}</div>
                    </div>
                    <div class="detail-row">
                        <strong>Next steps</strong>
                        <div>
                            <code>php artisan migrate</code><br>
                            <code>php artisan app:doctor</code><br>
                            Refresh this page after the schema is ready.
                        </div>
                    </div>
                </div>
            @endif
        </section>
    @else
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
    @endif

    <div class="grid cols-2">
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Ops Status</h2>
                <span class="badge {{ $badgeClass($metrics['ops_status']) }}">{{ $metrics['ops_status'] }}</span>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Queue mode</strong><div>{{ $metrics['ops']['queue_mode'] }}</div></div>
                <div class="detail-row"><strong>Scheduler heartbeat</strong><div><span class="badge {{ $badgeClass($metrics['ops']['scheduler_heartbeat']['status']) }}">{{ $metrics['ops']['scheduler_heartbeat']['status'] }}</span> {{ optional($metrics['ops']['scheduler_heartbeat']['last_seen_at'])->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Worker heartbeat</strong><div><span class="badge {{ $badgeClass($metrics['ops']['worker_heartbeat']['status']) }}">{{ $metrics['ops']['worker_heartbeat']['status'] }}</span> {{ optional($metrics['ops']['worker_heartbeat']['last_seen_at'])->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Failed jobs</strong><div>{{ $metrics['ops']['failed_jobs']['count'] }}</div></div>
                <div class="detail-row"><strong>Broken Prom API auth</strong><div>{{ $metrics['ops']['broken_prom_api_connections_count'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Due source connections</strong><div>{{ $metrics['ops']['due_source_connections_count'] }}</div></div>
                <div class="detail-row"><strong>Due feed builds</strong><div>{{ $metrics['ops']['due_feed_builds_count'] }}</div></div>
                <div class="detail-row"><strong>Due feed publishes</strong><div>{{ $metrics['ops']['due_feed_publishes_count'] }}</div></div>
            </div>
            @if(($metrics['ops']['broken_prom_api_connections_count'] ?? 0) > 0)
                <div class="detail-list" style="margin-top: 16px;">
                    @foreach($metrics['ops']['broken_prom_api_connections'] as $brokenConnection)
                        <div class="detail-row">
                            <strong>{{ $brokenConnection->name }}</strong>
                            <div>{{ $brokenConnection->last_sync_message ?: $brokenConnection->last_connection_check_message ?: 'Authentication failed.' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
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
            <p class="muted">
                @if($shop)
                    Shop: {{ $shop->name }} ({{ $shop->slug }})
                @else
                    Shop context will appear after the schema is ready.
                @endif
            </p>
        </section>
    </div>
@endsection
