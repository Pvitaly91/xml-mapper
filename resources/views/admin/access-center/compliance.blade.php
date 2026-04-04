@extends('layouts.admin', ['title' => 'Compliance Report'])

@section('subtitle', 'Filter governance audits, approval history, and sensitive action traces by shop, user, date, and severity.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.access.index') }}">Access Center</a>
            <a class="button secondary" href="{{ route('admin.access.compliance.export', request()->query()) }}">Download JSON report</a>
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
                <label for="filter_status">Approval status</label>
                <select id="filter_status" name="status">
                    <option value="">all</option>
                    @foreach(\App\Models\ApprovalRequest::statuses() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_action">Action</label>
                <input id="filter_action" name="action" value="{{ request('action') }}">
            </div>
            <div class="field">
                <label for="filter_event_type">Audit event</label>
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
        <h2>Governance Audits</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Category</th><th>Event</th><th>User</th><th>Shop</th><th>Target</th><th>Correlation</th></tr></thead>
                <tbody>
                @forelse($audits as $audit)
                    <tr>
                        <td>{{ optional($audit->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $audit->category }}</td>
                        <td>{{ $audit->event_type }}<br><span class="muted">{{ $audit->summary }}</span></td>
                        <td>{{ $audit->user?->email ?: 'system' }}</td>
                        <td>{{ $audit->shop?->name ?: 'platform' }}</td>
                        <td>{{ $audit->target_label ?: 'n/a' }}</td>
                        <td><code>{{ $audit->correlation_id ?: 'n/a' }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No governance audit entries matched the current filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $audits->links() }}</div>
    </section>

    <section class="panel">
        <h2>Approval History</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Action</th><th>Status</th><th>Requester</th><th>Approver</th><th>Shop</th><th>Target</th><th></th></tr></thead>
                <tbody>
                @forelse($approvals as $approval)
                    <tr>
                        <td>#{{ $approval->id }}</td>
                        <td>{{ $approval->action }}<br><span class="muted">{{ $approval->classification }}</span></td>
                        <td>{{ $approval->status }}</td>
                        <td>{{ $approval->requestedBy?->email ?: 'system' }}</td>
                        <td>{{ $approval->approvedBy?->email ?: 'n/a' }}</td>
                        <td>{{ $approval->shop?->name ?: 'platform' }}</td>
                        <td>{{ $approval->target_label ?: 'n/a' }}</td>
                        <td><a class="button link" href="{{ route('admin.access.approvals.show', $approval) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No approval requests matched the current filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $approvals->links() }}</div>
    </section>
@endsection
