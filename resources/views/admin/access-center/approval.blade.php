@extends('layouts.admin', ['title' => 'Approval #'.$approval->id])

@section('subtitle', 'Review the requested action payload, risk level, approval policy, and immutable governance history before execution.')

@section('safety_banner')
    <strong>Approval execution is final for this queued payload</strong>
    Review the environment, risk class, 4-eyes requirement, and payload summary before approving. Approval executes the stored request payload, not a new free-form action.
@endsection

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.access.index') }}">Access Center</a>
            <a class="button secondary" href="{{ route('admin.access.compliance') }}">Compliance</a>
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">Status</span><strong>{{ $approval->status }}</strong></div>
            <div class="stat"><span class="muted">Risk</span><strong>{{ $approval->classification }}</strong></div>
            <div class="stat"><span class="muted">Environment</span><strong>{{ $approval->environment_label }}</strong></div>
            <div class="stat"><span class="muted">4-eyes</span><strong>{{ $approval->requires_four_eyes ? 'yes' : 'no' }}</strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Request</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Action</strong><div>{{ $approval->action }}</div></div>
                <div class="detail-row"><strong>Requester</strong><div>{{ $approval->requestedBy?->name ?: 'system' }}<br><span class="muted">{{ $approval->requestedBy?->email ?: 'n/a' }}</span></div></div>
                <div class="detail-row"><strong>Approver</strong><div>{{ $approval->approvedBy?->email ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Shop</strong><div>{{ $approval->shop?->name ?: 'platform' }}</div></div>
                <div class="detail-row"><strong>Target</strong><div>{{ $approval->target_label ?: class_basename($approval->target_type ?: 'n/a') }}</div></div>
                <div class="detail-row"><strong>Reason</strong><div>{{ $approval->reason ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Note</strong><div>{{ $approval->note ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Requested at</strong><div>{{ optional($approval->requested_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Expires at</strong><div>{{ optional($approval->expires_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Correlation</strong><div><code>{{ $approval->correlation_id ?: 'n/a' }}</code></div></div>
            </div>
        </section>

        <section class="panel">
            <h2>Decision</h2>
            @if($approval->status === 'pending')
                <form method="POST" action="{{ route('admin.access.approvals.approve', $approval) }}" style="margin-bottom: 16px;">
                    @csrf
                    <div class="field">
                        <label for="approve_note">Approval note</label>
                        <textarea id="approve_note" name="note" placeholder="Why the action is safe to execute"></textarea>
                    </div>
                    <button class="button" type="submit" style="margin-top: 12px;" data-testid="approval-approve-submit">Approve and execute</button>
                </form>
                <form method="POST" action="{{ route('admin.access.approvals.reject', $approval) }}">
                    @csrf
                    <div class="field">
                        <label for="reject_note">Rejection note</label>
                        <textarea id="reject_note" name="note" placeholder="Why the request is blocked or rejected"></textarea>
                    </div>
                    <button class="button danger" type="submit" style="margin-top: 12px;" data-testid="approval-reject-submit">Reject</button>
                </form>
            @else
                <p class="muted">This approval request is already in terminal or executed state.</p>
            @endif
        </section>
    </div>

    <section class="panel">
        <h2>Payload Summary</h2>
        <pre>{{ json_encode($approval->payload_summary ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>

    <section class="panel">
        <h2>Execution Result</h2>
        <pre>{{ json_encode($approval->result_summary ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>

    <section class="panel">
        <h2>Governance History</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Event</th><th>User</th><th>Summary</th><th>Correlation</th></tr></thead>
                <tbody>
                @forelse($approval->audits as $audit)
                    <tr>
                        <td>{{ optional($audit->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $audit->event_type }}</td>
                        <td>{{ $audit->user?->email ?: 'system' }}</td>
                        <td>{{ $audit->summary }}</td>
                        <td><code>{{ $audit->correlation_id ?: 'n/a' }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No linked audit records found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
