@extends('layouts.admin', ['title' => 'Auth Audit'])

@section('subtitle', 'Review invite, login, MFA, re-auth, session revoke, and break-glass events with compliance-friendly filters.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.access.index') }}">Access Center</a>
            <a class="button secondary" href="{{ route('admin.access.compliance.export', array_merge(request()->query(), ['category' => 'auth'])) }}">Download JSON report</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="filter_shop">Shop</label>
                <select id="filter_shop" name="shop_id">
                    <option value="">all</option>
                    @foreach($shops as $shop)
                        <option value="{{ $shop->id }}" @selected((string) request('shop_id') === (string) $shop->id)>{{ $shop->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_user">User</label>
                <select id="filter_user" name="user_id">
                    <option value="">all</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->email }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_event_type">Event</label>
                <input id="filter_event_type" name="event_type" value="{{ request('event_type') }}">
            </div>
            <div class="field">
                <label for="filter_severity">Severity</label>
                <select id="filter_severity" name="severity">
                    <option value="">all</option>
                    @foreach(['info', 'warning', 'error'] as $severity)
                        <option value="{{ $severity }}" @selected(request('severity') === $severity)>{{ $severity }}</option>
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
                <button class="button" type="submit">Apply filters</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Auth Security Events</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Event</th><th>User</th><th>Severity</th><th>Summary</th><th>Correlation</th></tr></thead>
                <tbody>
                @forelse($audits as $audit)
                    <tr>
                        <td>{{ optional($audit->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $audit->event_type }}</td>
                        <td>{{ $audit->user?->email ?: 'system' }}</td>
                        <td>{{ $audit->severity }}</td>
                        <td>{{ $audit->summary }}<br><span class="muted">{{ $audit->target_label ?: 'n/a' }}</span></td>
                        <td><code>{{ $audit->correlation_id ?: 'n/a' }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No auth audit entries matched the current filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $audits->links() }}</div>
    </section>
@endsection
