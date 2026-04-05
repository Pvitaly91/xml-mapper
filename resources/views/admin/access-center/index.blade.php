@extends('layouts.admin', ['title' => 'Access Center'])

@section('subtitle', 'Role assignments, shop-scoped memberships, pending approvals, and governance audit visibility for production operations.')

@section('safety_banner')
    <strong>Governance actions are scoped</strong>
    The current shop and role in the shell decide what you can see here. Sensitive resets, break-glass, and approval reviews remain audited and can still require fresh password or MFA confirmation.
@endsection

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a class="button secondary" href="{{ route('admin.access.compliance') }}">Compliance</a>
            <a class="button secondary" href="{{ route('admin.access.auth-audit') }}">Auth Audit</a>
            <a class="button secondary" href="{{ route('admin.access.sessions') }}">Sessions</a>
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">Current shop</span><strong>{{ $currentShop?->name ?: 'Platform scope' }}</strong></div>
            <div class="stat"><span class="muted">Visible members</span><strong>{{ $memberships?->total() ?? 0 }}</strong></div>
            <div class="stat"><span class="muted">Pending invites</span><strong>{{ $invites->getCollection()->where('status', 'pending')->count() }}</strong></div>
            <div class="stat"><span class="muted">Pending approvals</span><strong>{{ $approvals->getCollection()->where('status', 'pending')->count() }}</strong></div>
            <div class="stat"><span class="muted">Recent audits</span><strong>{{ $audits->total() }}</strong></div>
            <div class="stat"><span class="muted">Auth audit</span><strong>{{ $authAudits->total() }}</strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Grant Membership</h2>
            <form method="POST" action="{{ route('admin.access.memberships.store') }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="membership_user_id">Existing user</label>
                        <select id="membership_user_id" name="user_id">
                            <option value="">create/use by email below</option>
                            @foreach($users as $userOption)
                                <option value="{{ $userOption->id }}">{{ $userOption->name }} ({{ $userOption->email }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="membership_role">Role</label>
                        <select id="membership_role" name="role" required>
                            @foreach($roles as $role)
                                <option value="{{ $role }}">{{ $role }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="membership_shop_id">Shop</label>
                        <select id="membership_shop_id" name="shop_id">
                            <option value="">platform scope</option>
                            @foreach($shops as $shopOption)
                                <option value="{{ $shopOption->id }}" @selected((int) ($currentShop?->id ?? 0) === (int) $shopOption->id)>{{ $shopOption->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="membership_status">Status</label>
                        <select id="membership_status" name="status">
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected($status === 'active')>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="membership_email">Email</label>
                        <input id="membership_email" name="email" type="email" placeholder="operator@example.com">
                    </div>
                    <div class="field">
                        <label for="membership_name">Name</label>
                        <input id="membership_name" name="name" placeholder="Internal operator name">
                    </div>
                    <div class="field">
                        <label for="membership_password">Password</label>
                        <input id="membership_password" name="password" type="password" placeholder="Only for new internal user">
                    </div>
                    <div class="field full">
                        <label for="membership_note">Note</label>
                        <textarea id="membership_note" name="note" placeholder="Why this access is needed"></textarea>
                    </div>
                </div>
                <button class="button" type="submit" style="margin-top: 12px;">Save membership</button>
            </form>
        </section>

        <section class="panel">
            <h2>Create Invite</h2>
            <form method="POST" action="{{ route('admin.access.invites.store') }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="invite_email">Email</label>
                        <input id="invite_email" name="email" type="email" placeholder="operator@example.com" required>
                    </div>
                    <div class="field">
                        <label for="invite_name">Name</label>
                        <input id="invite_name" name="name" placeholder="Internal operator name">
                    </div>
                    <div class="field">
                        <label for="invite_role">Role</label>
                        <select id="invite_role" name="role" required>
                            @foreach($roles as $role)
                                <option value="{{ $role }}">{{ $role }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="invite_shop_id">Shop</label>
                        <select id="invite_shop_id" name="shop_id">
                            <option value="">platform scope</option>
                            @foreach($shops as $shopOption)
                                <option value="{{ $shopOption->id }}" @selected((int) ($currentShop?->id ?? 0) === (int) $shopOption->id)>{{ $shopOption->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field full">
                        <label for="invite_note">Reason</label>
                        <textarea id="invite_note" name="note" placeholder="Why this invite is needed"></textarea>
                    </div>
                </div>
                <button class="button" type="submit" style="margin-top: 12px;">Create invite</button>
            </form>
            @if(session('invite_accept_url'))
                <p style="margin-top: 16px;">Current invite link:</p>
                <code>{{ session('invite_accept_url') }}</code>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Secret Governance Snapshot</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Connection</th><th>Driver</th><th>Masked secret</th><th>State</th><th>Validated</th></tr></thead>
                    <tbody>
                    @forelse($secretState as $item)
                        <tr>
                            <td><a class="button link" href="{{ route('admin.source-connections.show', $item['id']) }}">{{ $item['name'] }}</a></td>
                            <td>{{ $item['driver'] }}</td>
                            <td>{{ $item['masked_secret'] }}</td>
                            <td>{{ $item['secret_state'] }}{{ $item['rebind_required'] ? ' / rebind required' : '' }}</td>
                            <td>{{ optional($item['last_validated_at'])->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No source connections are visible in the current shop scope.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="panel">
            <h2>Current Security Session</h2>
            <p class="muted">Use break-glass only for a clearly documented emergency and end it as soon as the safer path is restored.</p>
            <div class="detail-list">
                <div class="detail-row"><strong>User</strong><div>{{ $sessionSubject?->email ?: auth()->user()->email }}</div></div>
                <div class="detail-row"><strong>Suspicious IPs (24h)</strong><div>{{ $suspiciousIpCount }}</div></div>
                <div class="detail-row"><strong>Break-glass</strong><div>{{ session('admin_auth.break_glass.expires_at') ? 'active until '.session('admin_auth.break_glass.expires_at') : 'inactive' }}</div></div>
            </div>
            <form method="POST" action="{{ route('admin.auth.break-glass.start') }}" style="margin-top: 16px; margin-bottom: 12px;">
                @csrf
                <div class="field">
                    <label for="break_glass_reason">Start Break-Glass</label>
                    <input id="break_glass_reason" name="reason" placeholder="Emergency reason required" data-testid="break-glass-reason">
                </div>
                <button class="button warning" type="submit" data-testid="break-glass-start">Start break-glass</button>
            </form>
            <form method="POST" action="{{ route('admin.auth.break-glass.end') }}">
                @csrf
                <div class="field">
                    <label for="break_glass_end_reason">End reason</label>
                    <input id="break_glass_end_reason" name="reason" placeholder="Optional closeout note">
                </div>
                <button class="button secondary" type="submit">End break-glass</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <h2>Shop Memberships</h2>
        @if($memberships)
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User</th><th>Role</th><th>Membership</th><th>Account</th><th>MFA</th><th>Shop</th><th>Note</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($memberships as $membership)
                        <tr>
                            <td>{{ $membership->user?->name ?: 'n/a' }}<br><span class="muted">{{ $membership->user?->email ?: 'n/a' }}</span></td>
                            <td>{{ $membership->role }}</td>
                            <td>{{ $membership->status }}</td>
                            <td>{{ $membership->user?->account_state ?: 'n/a' }}</td>
                            <td>{{ $membership->user?->mfaStatus() ?: 'n/a' }}<br><span class="muted">{{ optional($membership->user?->mfa_last_verified_at)->format('Y-m-d H:i') ?: 'never' }}</span></td>
                            <td>{{ $membership->shop?->name ?: 'platform' }}</td>
                            <td>{{ $membership->note ?: 'n/a' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.access.memberships.update', $membership) }}" style="margin-bottom: 8px;">
                                    @csrf
                                    @method('PUT')
                                    <select name="status">
                                        @foreach($statuses as $status)
                                            <option value="{{ $status }}" @selected($membership->status === $status)>{{ $status }}</option>
                                        @endforeach
                                    </select>
                                    <input name="note" placeholder="status note">
                                    <button class="button secondary" type="submit">Update</button>
                                </form>
                                <form method="POST" action="{{ route('admin.access.memberships.revoke', $membership) }}">
                                    @csrf
                                    <input name="note" placeholder="revoke note">
                                    <button class="button danger" type="submit">Revoke</button>
                                </form>
                                <form method="POST" action="{{ route('admin.access.users.force-password-reset', $membership->user) }}" style="margin-top: 8px;">
                                    @csrf
                                    <input name="reason" placeholder="force reset reason">
                                    <button class="button warning" type="submit">Force Password Reset</button>
                                </form>
                                <form method="POST" action="{{ route('admin.access.users.reset-mfa', $membership->user) }}" style="margin-top: 8px;">
                                    @csrf
                                    <input name="reason" placeholder="reset MFA reason">
                                    <button class="button warning" type="submit">Reset MFA</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No memberships found for the current shop scope.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 14px;">{{ $memberships->links() }}</div>
        @else
            <p class="muted">No shop is currently selected.</p>
        @endif
    </section>

    <section class="panel">
        <h2>Invites</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Email</th><th>Role</th><th>Status</th><th>Expires</th><th>Shop</th><th></th></tr></thead>
                <tbody>
                @forelse($invites as $invite)
                    <tr>
                        <td>#{{ $invite->id }}</td>
                        <td>{{ $invite->email }}</td>
                        <td>{{ $invite->membership?->role ?: 'n/a' }}</td>
                        <td>{{ $invite->status }}</td>
                        <td>{{ optional($invite->expires_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $invite->membership?->shop?->name ?: 'platform' }}</td>
                        <td><a class="button link" href="{{ route('admin.access.invites.show', $invite) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No invites in scope.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $invites->links() }}</div>
    </section>

    <section class="panel">
        <h2>Pending Approval Queue</h2>
        <p class="muted">If approval is blocked, the detail screen tells the operator whether password re-authentication, MFA re-authentication, or 4-eyes review is still missing.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Action</th><th>Risk</th><th>Status</th><th>Requester</th><th>Shop</th><th>Target</th><th></th></tr></thead>
                <tbody>
                @forelse($approvals as $approval)
                    <tr>
                        <td>#{{ $approval->id }}</td>
                        <td>{{ $approval->action }}</td>
                        <td>{{ $approval->classification }}{{ $approval->requires_four_eyes ? ' / 4-eyes' : '' }}</td>
                        <td>{{ $approval->status }}</td>
                        <td>{{ $approval->requestedBy?->email ?: 'system' }}</td>
                        <td>{{ $approval->shop?->name ?: 'platform' }}</td>
                        <td>{{ $approval->target_label ?: class_basename($approval->target_type ?: 'n/a') }}</td>
                        <td><a class="button link" href="{{ route('admin.access.approvals.show', $approval) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No approval requests in scope.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $approvals->links() }}</div>
    </section>

    <section class="panel">
        <h2>Recent Governance Audit</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Category</th><th>Event</th><th>User</th><th>Target</th><th>Correlation</th></tr></thead>
                <tbody>
                @forelse($audits as $audit)
                    <tr>
                        <td>{{ optional($audit->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $audit->category }}</td>
                        <td>{{ $audit->event_type }}<br><span class="muted">{{ $audit->summary }}</span></td>
                        <td>{{ $audit->user?->email ?: 'system' }}</td>
                        <td>{{ $audit->target_label ?: 'n/a' }}</td>
                        <td><code>{{ $audit->correlation_id ?: 'n/a' }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No governance audit entries found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $audits->links() }}</div>
    </section>

    <section class="panel">
        <h2>Recent Auth Audit</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Event</th><th>User</th><th>Summary</th><th>Correlation</th></tr></thead>
                <tbody>
                @forelse($authAudits as $audit)
                    <tr>
                        <td>{{ optional($audit->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $audit->event_type }}</td>
                        <td>{{ $audit->user?->email ?: 'system' }}</td>
                        <td>{{ $audit->summary }}</td>
                        <td><code>{{ $audit->correlation_id ?: 'n/a' }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No auth audit entries found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px;">{{ $authAudits->links() }}</div>
    </section>
@endsection
