@extends('layouts.admin', ['title' => $shop->name.' Go-Live Control'])

@section('subtitle', 'Daily operator control panel for source health, unresolved mappings, candidate releases, smoke checks, and publish readiness.')

@section('content')
    @php($feedProfile = $panel['feed_profile'])
    @php($sourceConnection = $panel['source_connection'])
    @php($latestGeneration = $panel['latest_generation'])
    @php($readiness = $panel['release_readiness'])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.onboarding.show') }}">Onboarding wizard</a>
            @if($sourceConnection)
                <form method="POST" action="{{ route('admin.source-connections.test', $sourceConnection) }}">
                    @csrf
                    <button class="button secondary" type="submit">Test connection</button>
                </form>
                <form method="POST" action="{{ route('admin.source-connections.sync', $sourceConnection) }}">
                    @csrf
                    <button class="button secondary" type="submit">Run sync</button>
                </form>
            @endif
            @if($feedProfile)
                <form method="POST" action="{{ route('admin.feed-profiles.workbench.suggestions', $feedProfile) }}">
                    @csrf
                    <button class="button secondary" type="submit">Run automap</button>
                </form>
                <form method="POST" action="{{ route('admin.onboarding.candidate') }}">
                    @csrf
                    <button class="button" type="submit">Build candidate</button>
                </form>
                <a class="button secondary" href="{{ route('admin.feed-profiles.workbench.index', $feedProfile) }}">Open unresolved workbench</a>
                <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Open release center</a>
            @endif
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Ready</span><strong>{{ $panel['feed_item_counts']['ready'] }}</strong></div>
        <div class="stat"><span class="muted">Invalid</span><strong>{{ $panel['feed_item_counts']['invalid'] }}</strong></div>
        <div class="stat"><span class="muted">Excluded</span><strong>{{ $panel['feed_item_counts']['excluded'] }}</strong></div>
        <div class="stat"><span class="muted">Missing categories</span><strong>{{ $panel['unresolved_counts']['missing_category_mapping'] }}</strong></div>
        <div class="stat"><span class="muted">Missing attributes</span><strong>{{ $panel['unresolved_counts']['missing_attribute_mapping'] }}</strong></div>
        <div class="stat"><span class="muted">Missing values</span><strong>{{ $panel['unresolved_counts']['missing_value_mapping'] }}</strong></div>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Source Status</h2>
            @if($sourceConnection)
                <div class="detail-list">
                    <div class="detail-row"><strong>Connection</strong><div>{{ $sourceConnection->name }} ({{ $sourceConnection->driver }})</div></div>
                    <div class="detail-row"><strong>Test status</strong><div>{{ $sourceConnection->last_connection_check_status ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Last sync</strong><div>{{ optional($sourceConnection->last_synced_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Sync status</strong><div>{{ $sourceConnection->last_sync_status ?: 'n/a' }}</div></div>
                </div>
            @else
                <p class="muted">No source connection configured yet.</p>
            @endif
        </section>

        <section class="panel">
            <h2>Release State</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Latest candidate</strong><div>{{ $panel['latest_candidate_generation']?->id ? '#'.$panel['latest_candidate_generation']->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Latest approved</strong><div>{{ $panel['latest_approved_generation']?->id ? '#'.$panel['latest_approved_generation']->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Latest published</strong><div>{{ $panel['latest_published_generation']?->id ? '#'.$panel['latest_published_generation']->id : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Smoke check</strong><div>{{ $panel['latest_smoke_check_status'] ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Publish guard</strong><div>{{ $panel['publish_allowed'] ? 'allowed' : 'blocked' }}</div></div>
            </div>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Latest Candidate Readiness</h2>
            @if($readiness)
                <div class="toolbar">
                    <span class="badge {{ $readiness['status'] === 'ready' ? 'ok' : ($readiness['status'] === 'blocked' ? 'err' : 'warn') }}">{{ $readiness['status'] }}</span>
                </div>
                <h3>Blocking issues</h3>
                @if($readiness['blocking_issues'] !== [])
                    <ul>
                        @foreach($readiness['blocking_issues'] as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted">No blocking issues.</p>
                @endif
                @if($readiness['warnings'] !== [])
                    <h3>Warnings</h3>
                    <ul>
                        @foreach($readiness['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="muted">Build a candidate generation to see release readiness.</p>
            @endif
        </section>

        <section class="panel">
            <h2>Onboarding Progress</h2>
            @if($panel['onboarding'])
                <div class="detail-list">
                    <div class="detail-row"><strong>Current step</strong><div>{{ str_replace('_', ' ', $panel['onboarding']['current_step']) }}</div></div>
                    <div class="detail-row"><strong>Completed</strong><div>{{ $panel['onboarding']['completed'] ? 'Yes' : 'No' }}</div></div>
                </div>
                <ul style="margin-top: 16px;">
                    @foreach($panel['onboarding']['steps'] as $step)
                        <li>{{ $step['label'] }}: {{ $step['status'] }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">Onboarding state is not available.</p>
            @endif
        </section>
    </div>
@endsection
