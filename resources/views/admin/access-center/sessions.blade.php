@extends('layouts.admin', ['title' => 'Session Governance'])

@section('subtitle', 'Inspect active admin sessions, MFA verification state, device context, and revoke sessions safely.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.access.index') }}">Access Center</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="session_user_id">User</label>
                <select id="session_user_id" name="session_user_id">
                    <option value="{{ auth()->id() }}">current user</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" @selected((string) request('session_user_id', $sessionSubject?->id) === (string) $user->id)>{{ $user->email }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="align-self: end;">
                <button class="button" type="submit">Load sessions</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="stats">
            <div class="stat"><span class="muted">Subject</span><strong>{{ $sessionSubject?->email ?: auth()->user()->email }}</strong></div>
            <div class="stat"><span class="muted">Sessions</span><strong>{{ $sessions->count() }}</strong></div>
            <div class="stat"><span class="muted">Suspicious IPs</span><strong>{{ $suspiciousIpCount }}</strong></div>
        </div>
    </section>

    <section class="panel">
        <h2>Sessions</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Device</th><th>IP</th><th>Seen</th><th>MFA</th><th>Break-glass</th><th>State</th><th></th></tr></thead>
                <tbody>
                @forelse($sessions as $session)
                    <tr>
                        <td><code>{{ $session->id }}</code>{{ $session->id === request()->session()->getId() ? ' (current)' : '' }}</td>
                        <td>{{ $session->device_label ?: 'n/a' }}<br><span class="muted">{{ $session->user_agent ?: 'n/a' }}</span></td>
                        <td>{{ $session->ip_address ?: 'n/a' }}</td>
                        <td>{{ optional($session->last_seen_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ optional($session->mfa_verified_at)->format('Y-m-d H:i:s') ?: 'not verified' }}</td>
                        <td>{{ optional($session->break_glass_expires_at)->format('Y-m-d H:i:s') ?: 'inactive' }}</td>
                        <td>{{ $session->revoked_at ? 'revoked' : 'active' }}</td>
                        <td>
                            @if(! $session->revoked_at)
                                <form method="POST" action="{{ route('admin.access.sessions.revoke', $session) }}">
                                    @csrf
                                    <input name="reason" placeholder="revoke reason">
                                    <button class="button danger" type="submit">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No sessions found for the selected user.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('admin.access.users.sessions.revoke', $sessionSubject ?: auth()->user()) }}" style="margin-top: 16px;">
            @csrf
            <input type="hidden" name="all" value="0">
            <div class="field">
                <label for="revoke_other_reason">Revoke other sessions</label>
                <input id="revoke_other_reason" name="reason" placeholder="Reason for revoking every non-current session">
            </div>
            <button class="button warning" type="submit" style="margin-top: 12px;">Revoke other sessions</button>
        </form>

        <form method="POST" action="{{ route('admin.access.users.sessions.revoke', $sessionSubject ?: auth()->user()) }}" style="margin-top: 16px;">
            @csrf
            <input type="hidden" name="all" value="1">
            <div class="field">
                <label for="revoke_all_reason">Revoke all sessions</label>
                <input id="revoke_all_reason" name="reason" placeholder="Emergency revoke reason">
            </div>
            <button class="button warning" type="submit" style="margin-top: 12px;">Revoke all sessions</button>
        </form>
    </section>
@endsection
