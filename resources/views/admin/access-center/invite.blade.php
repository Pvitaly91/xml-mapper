@extends('layouts.admin', ['title' => 'Invite #'.$invite->id])

@section('subtitle', 'Review invite lifecycle, acceptance status, expiry, and current acceptance link for internal admin onboarding.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.access.index') }}">Access Center</a>
            <a class="button secondary" href="{{ route('admin.access.auth-audit') }}">Auth Audit</a>
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">Status</span><strong>{{ $invite->status }}</strong></div>
            <div class="stat"><span class="muted">Email</span><strong>{{ $invite->email }}</strong></div>
            <div class="stat"><span class="muted">Role</span><strong>{{ $invite->membership?->role ?: 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">Expires</span><strong>{{ optional($invite->expires_at)->format('Y-m-d H:i') ?: 'n/a' }}</strong></div>
        </div>
    </section>

    <section class="panel">
        <h2>Invite Details</h2>
        <div class="detail-list">
            <div class="detail-row"><strong>Shop</strong><div>{{ $invite->membership?->shop?->name ?: 'platform' }}</div></div>
            <div class="detail-row"><strong>Membership status</strong><div>{{ $invite->membership?->status ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Requested by</strong><div>{{ $invite->requestedBy?->email ?: 'system' }}</div></div>
            <div class="detail-row"><strong>Accepted at</strong><div>{{ optional($invite->accepted_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Revoked at</strong><div>{{ optional($invite->revoked_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Note</strong><div>{{ $invite->note ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Acceptance URL</strong><div>@if($acceptUrl)<code>{{ $acceptUrl }}</code>@else n/a @endif</div></div>
        </div>
    </section>

    <section class="panel">
        <h2>Actions</h2>
        <form method="POST" action="{{ route('admin.access.invites.resend', $invite) }}" style="margin-bottom: 16px;">
            @csrf
            <button class="button" type="submit">Resend invite</button>
        </form>

        <form method="POST" action="{{ route('admin.access.invites.revoke', $invite) }}">
            @csrf
            <div class="field">
                <label for="invite_revoke_reason">Revoke reason</label>
                <input id="invite_revoke_reason" name="reason" placeholder="Why the invite is no longer valid">
            </div>
            <button class="button danger" type="submit" style="margin-top: 12px;">Revoke invite</button>
        </form>
    </section>
@endsection
