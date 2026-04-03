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
    @elseif($shop === null)
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Shop Onboarding Required</h2>
                <span class="badge warn">pending</span>
            </div>
            <p class="muted">This admin user is authenticated, but no shop is assigned yet. Start the onboarding wizard to create the shop, configure the source, and build the first release candidate.</p>
            <div class="toolbar" style="margin-top: 16px;">
                <a class="button" href="{{ route('admin.onboarding.show') }}">Open onboarding wizard</a>
                <a class="button secondary" href="{{ route('admin.dictionaries.index') }}">Check dictionaries</a>
            </div>
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

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Environment</h2>
            <span class="badge {{ $metrics['environment']['badge_class'] }}">{{ $metrics['environment']['label'] }}</span>
        </div>
        @if(($metrics['environment']['warnings'] ?? []) !== [])
            <ul>
                @foreach($metrics['environment']['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @else
            <p class="muted">Environment separation indicators are healthy.</p>
        @endif
    </section>

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
            <div class="toolbar">
                <h2 style="margin: 0;">Maintenance</h2>
                <form method="POST" action="{{ route('admin.ops.preflight') }}">
                    @csrf
                    <button class="button secondary" type="submit">Run preflight</button>
                </form>
                <form method="POST" action="{{ route('admin.ops.backup-db') }}">
                    @csrf
                    <button class="button secondary" type="submit">Backup DB</button>
                </form>
                <form method="POST" action="{{ route('admin.ops.backup-files') }}">
                    @csrf
                    <button class="button secondary" type="submit">Backup files</button>
                </form>
                <form method="POST" action="{{ route('admin.ops.prune') }}">
                    @csrf
                    <button class="button warning" type="submit">Run prune</button>
                </form>
            </div>
            <div class="detail-list">
                <div class="detail-row">
                    <strong>Last preflight</strong>
                    <div>
                        {{ optional($metrics['maintenance']['last_preflight']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}
                        @if($metrics['maintenance']['last_preflight'])
                            @php($preflightBadgeStatus = $metrics['maintenance']['last_preflight']->status === 'succeeded' ? 'ok' : ($metrics['maintenance']['last_preflight']->status === 'warning' ? 'setup_required' : 'failed'))
                            <span class="badge {{ $badgeClass($preflightBadgeStatus) }}">{{ $metrics['maintenance']['last_preflight']->status }}</span>
                        @endif
                    </div>
                </div>
                <div class="detail-row"><strong>Last DB backup</strong><div>{{ optional($metrics['maintenance']['last_backup_db']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last files backup</strong><div>{{ optional($metrics['maintenance']['last_backup_files']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last prune</strong><div>{{ optional($metrics['maintenance']['last_prune']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last deploy</strong><div>{{ optional($metrics['maintenance']['last_deploy']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Storage used</strong><div>{{ number_format(($metrics['maintenance']['storage']['total_bytes'] ?? 0) / 1024 / 1024, 2) }} MB</div></div>
            </div>
            @if(($metrics['maintenance']['retention_warnings'] ?? []) !== [])
                <ul class="error-list" style="margin-top: 14px;">
                    @foreach($metrics['maintenance']['retention_warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
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
                <a class="button" href="{{ route('admin.onboarding.show') }}">Onboarding wizard</a>
                @if($shop)
                    <a class="button secondary" href="{{ route('admin.shop-control.show') }}">Go-live control panel</a>
                    <a class="button secondary" href="{{ route('admin.source-connections.index') }}">Manage source connections</a>
                    <a class="button secondary" href="{{ route('admin.feed-profiles.index') }}">Manage feed profiles</a>
                @endif
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

    <section class="panel">
        <h2>Queue Backlog</h2>
        <div class="stats">
            @foreach($metrics['maintenance']['queue_backlog'] ?? [] as $queue => $size)
                <div class="stat">
                    <span class="muted">{{ $queue }}</span>
                    <strong>{{ $size ?? 'n/a' }}</strong>
                </div>
            @endforeach
        </div>
    </section>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Reliability Summary</h2>
            <span class="badge {{ ($metrics['slo']['status'] ?? 'healthy') === 'healthy' ? 'ok' : (($metrics['slo']['status'] ?? 'healthy') === 'warning' ? 'warn' : 'err') }}">{{ $metrics['slo']['status'] ?? 'healthy' }}</span>
        </div>
        <div class="stats">
            @php($slo24 = $metrics['slo']['windows']['24h'] ?? null)
            @php($slo7d = $metrics['slo']['windows']['168h'] ?? null)
            <div class="stat"><span class="muted">24h sync rate</span><strong>{{ $slo24 ? (($slo24['sync']['rate'] ?? null) !== null ? number_format(($slo24['sync']['rate'] ?? 0) * 100, 1).'%' : 'n/a') : 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">24h publish rate</span><strong>{{ $slo24 ? (($slo24['publish']['rate'] ?? null) !== null ? number_format(($slo24['publish']['rate'] ?? 0) * 100, 1).'%' : 'n/a') : 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">24h first-pull rate</span><strong>{{ $slo24 ? (($slo24['first_pull']['rate'] ?? null) !== null ? number_format(($slo24['first_pull']['rate'] ?? 0) * 100, 1).'%' : 'n/a') : 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">7d status</span><strong>{{ $slo7d['status'] ?? 'n/a' }}</strong></div>
        </div>
    </section>
@endsection
