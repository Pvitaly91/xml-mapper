@extends('layouts.admin', ['title' => 'Notification Center'])

@section('subtitle', 'Outbound delivery history, channel routing, retry/test actions, and suppression visibility for live support.')

@section('safety_banner')
    <strong>Use test delivery before relying on a route in production</strong>
    Test messages write delivery history, respect redaction, and make channel failures debuggable without waiting for a real incident.
@endsection

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a class="button secondary" href="{{ route('admin.merchant-launches.index') }}">Launch center</a>
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">Routes</span><strong>{{ count($channelStatus['routes']) }}</strong></div>
            <div class="stat"><span class="muted">Recent failed</span><strong>{{ $deliveries->getCollection()->where('status', 'failed')->count() }}</strong></div>
            <div class="stat"><span class="muted">Recent suppressed</span><strong>{{ $deliveries->getCollection()->where('status', 'suppressed')->count() }}</strong></div>
            <div class="stat"><span class="muted">Recent escalated</span><strong>{{ $deliveries->getCollection()->where('status', 'escalated')->count() }}</strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Send Test Notification</h2>
            <form method="POST" action="{{ route('admin.notifications.test') }}" data-testid="notification-test-form">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="test_channel">Channel</label>
                        <select id="test_channel" name="channel" data-testid="notification-test-channel">
                            @foreach(['database','log','email','webhook'] as $channel)
                                <option value="{{ $channel }}">{{ $channel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field full">
                        <label for="test_target">Target</label>
                        <input id="test_target" name="target" placeholder="emails comma-separated / webhook URL / log channel / blank for database admins" data-testid="notification-test-target">
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;" data-testid="notification-test-submit">Send test</button>
            </form>
        </section>

        <section class="panel">
            <h2>Create Route</h2>
            <form method="POST" action="{{ route('admin.notifications.routes.store') }}" data-testid="notification-route-form">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="route_name">Name</label>
                        <input id="route_name" name="name" required data-testid="notification-route-name">
                    </div>
                    <div class="field">
                        <label for="route_scope">Scope</label>
                        <select id="route_scope" name="scope" data-testid="notification-route-scope">
                            <option value="global">global</option>
                            <option value="shop">shop</option>
                            <option value="feed_profile">feed_profile</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="route_channel">Channel</label>
                        <select id="route_channel" name="channel" data-testid="notification-route-channel">
                            @foreach(['database','log','email','webhook'] as $channel)
                                <option value="{{ $channel }}">{{ $channel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="route_profile">Feed profile</label>
                        <select id="route_profile" name="feed_profile_id">
                            <option value="">shop/global default</option>
                            @foreach($feedProfiles as $feedProfile)
                                <option value="{{ $feedProfile->id }}">{{ $feedProfile->name }} ({{ $feedProfile->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="route_family">Event family</label>
                        <select id="route_family" name="event_family">
                            @foreach(['*','source_auth_broken','sync_failed','build_failed','publish_failed','smoke_failed','first_pull_failed','promotion_blocked','signoff_blocked','hypercare_critical_issue','rejection_spike','launch_degraded','rollback_executed','test'] as $family)
                                <option value="{{ $family }}">{{ $family }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="route_type">Event type</label>
                        <input id="route_type" name="event_type" placeholder="optional exact event match">
                    </div>
                    <div class="field">
                        <label for="route_severity">Minimum severity</label>
                        <select id="route_severity" name="minimum_severity">
                            @foreach(['info','low','warning','medium','critical','error'] as $severity)
                                <option value="{{ $severity }}">{{ $severity }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="route_target">Target value</label>
                        <input id="route_target" name="target_value" placeholder="emails / webhook URL / log channel" data-testid="notification-route-target">
                    </div>
                    <div class="field">
                        <label for="route_qs">Quiet start</label>
                        <input id="route_qs" type="time" name="quiet_hours_start">
                    </div>
                    <div class="field">
                        <label for="route_qe">Quiet end</label>
                        <input id="route_qe" type="time" name="quiet_hours_end">
                    </div>
                    <div class="field">
                        <label for="route_qtz">Quiet timezone</label>
                        <input id="route_qtz" name="quiet_hours_timezone" value="{{ $shop->timezone }}">
                    </div>
                    <div class="field">
                        <label for="route_max_attempts">Max attempts</label>
                        <input id="route_max_attempts" type="number" min="1" max="10" name="max_attempts" value="3">
                    </div>
                    <div class="field">
                        <label for="route_timeout">Timeout (s)</label>
                        <input id="route_timeout" type="number" min="1" max="120" name="timeout_seconds" value="5">
                    </div>
                    <div class="field">
                        <label for="route_repeat">Repeat interval (min)</label>
                        <input id="route_repeat" type="number" min="0" name="repeat_interval_minutes" value="30">
                    </div>
                    <div class="field">
                        <label for="route_suppress">Suppress window (min)</label>
                        <input id="route_suppress" type="number" min="0" name="suppression_window_minutes" value="15">
                    </div>
                    <div class="field">
                        <label for="route_escalate">Escalate after (min)</label>
                        <input id="route_escalate" type="number" min="0" name="escalate_after_minutes" value="15">
                    </div>
                    <div class="field">
                        <label for="route_muted">Muted until</label>
                        <input id="route_muted" type="datetime-local" name="muted_until">
                    </div>
                </div>
                <button class="button" type="submit" style="margin-top: 12px;" data-testid="notification-route-submit">Save route</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <h2>Routes</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Route</th><th>Scope</th><th>Event</th><th>Target</th><th>Health</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($routes as $route)
                    <tr>
                        <td>
                            <strong>{{ $route->name }}</strong><br>
                            <span class="muted">{{ $route->channel }} / {{ $route->minimum_severity }}</span>
                        </td>
                        <td>{{ $route->scope }}{{ $route->feedProfile ? ' / '.$route->feedProfile->code : '' }}</td>
                        <td>{{ $route->event_family ?: '*' }}<br><span class="muted">{{ $route->event_type ?: '*' }}</span></td>
                        <td>{{ $route->target_label ?: 'n/a' }}</td>
                        <td>
                            <span class="muted">last: {{ $route->last_delivery_status ?: 'n/a' }}</span><br>
                            <span class="muted">test ok: {{ optional($route->last_test_succeeded_at)->format('Y-m-d H:i') ?: 'n/a' }}</span><br>
                            <span class="muted">test fail: {{ optional($route->last_test_failed_at)->format('Y-m-d H:i') ?: 'n/a' }}</span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.notifications.routes.test', $route) }}" style="margin-bottom: 8px;">
                                @csrf
                                <button class="button secondary" type="submit">Test</button>
                            </form>
                            <form method="POST" action="{{ route('admin.notifications.routes.mute', $route) }}" style="margin-bottom: 8px;">
                                @csrf
                                <input type="datetime-local" name="until">
                                <button class="button warning" type="submit">Mute</button>
                            </form>
                            <form method="POST" action="{{ route('admin.notifications.routes.update', $route) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="name" value="{{ $route->name }}">
                                <input type="hidden" name="scope" value="{{ $route->scope }}">
                                <input type="hidden" name="channel" value="{{ $route->channel }}">
                                <input type="hidden" name="event_family" value="{{ $route->event_family ?: '*' }}">
                                <input type="hidden" name="event_type" value="{{ $route->event_type ?: '*' }}">
                                <input type="hidden" name="minimum_severity" value="{{ $route->minimum_severity }}">
                                <input type="hidden" name="feed_profile_id" value="{{ $route->feed_profile_id }}">
                                <input type="hidden" name="target_value" value="{{ data_get($route->target, 'url') ?: implode(',', (array) data_get($route->target, 'emails', [data_get($route->target, 'channel')])) }}">
                                <input type="hidden" name="enabled" value="{{ $route->enabled ? '0' : '1' }}">
                                <button class="button link" type="submit">{{ $route->enabled ? 'Disable' : 'Enable' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No persisted routes yet. Fallback database/log routes will still be used.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Deliveries</h2>
        <form method="GET" class="filters">
            <div class="field">
                <label for="filter_channel">Channel</label>
                <select id="filter_channel" name="channel">
                    <option value="">all</option>
                    @foreach(['database','log','email','webhook','unrouted'] as $channel)
                        <option value="{{ $channel }}" @selected(request('channel') === $channel)>{{ $channel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_status">Status</label>
                <select id="filter_status" name="status">
                    <option value="">all</option>
                    @foreach(['pending_delivery','delivered','failed','acknowledged','suppressed','escalated','resolved','dropped'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_severity">Severity</label>
                <select id="filter_severity" name="severity">
                    <option value="">all</option>
                    @foreach(['info','warning','critical','error','low','medium','high'] as $severity)
                        <option value="{{ $severity }}" @selected(request('severity') === $severity)>{{ $severity }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_event_type">Event type</label>
                <input id="filter_event_type" name="event_type" value="{{ request('event_type') }}">
            </div>
            <div class="field">
                <label for="filter_profile">Feed profile</label>
                <select id="filter_profile" name="feed_profile_id">
                    <option value="">all</option>
                    @foreach($feedProfiles as $feedProfile)
                        <option value="{{ $feedProfile->id }}" @selected((string) request('feed_profile_id') === (string) $feedProfile->id)>{{ $feedProfile->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_from">From</label>
                <input id="filter_from" type="date" name="from" value="{{ request('from') }}">
            </div>
            <div class="field">
                <label for="filter_to">To</label>
                <input id="filter_to" type="date" name="to" value="{{ request('to') }}">
            </div>
            <div class="field" style="align-self: end;">
                <button class="button secondary" type="submit">Filter</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Event</th><th>Channel</th><th>Target</th><th>Status</th><th>Correlation</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($deliveries as $delivery)
                    <tr>
                        <td>{{ optional($delivery->created_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            <strong>{{ $delivery->summary ?: $delivery->event_type }}</strong><br>
                            <span class="muted">{{ $delivery->event_type }} / {{ $delivery->severity }}</span>
                        </td>
                        <td>{{ $delivery->channel }}</td>
                        <td>{{ $delivery->target_label ?: 'n/a' }}</td>
                        <td>{{ $delivery->status }}<br><span class="muted">attempts: {{ $delivery->attempts }}</span></td>
                        <td><code>{{ $delivery->correlation_id ?: 'n/a' }}</code></td>
                        <td>
                            <a class="button link" href="{{ route('admin.notifications.deliveries.show', $delivery) }}">Details</a>
                            @if(in_array($delivery->status, ['failed','pending_delivery'], true))
                                <form method="POST" action="{{ route('admin.notifications.deliveries.retry', $delivery) }}" style="margin-top: 8px;">
                                    @csrf
                                    <button class="button secondary" type="submit">Retry</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No deliveries yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $deliveries->links() }}</div>
    </section>
@endsection
