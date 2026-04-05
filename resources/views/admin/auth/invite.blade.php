@extends('layouts.guest')

@section('content')
    <h1>Accept Admin Invite</h1>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif

    @if(! $invite)
        <p>This invite is invalid, revoked, or expired.</p>
    @else
        <p>Activate admin access for <strong>{{ $invite->email }}</strong> and finish the initial security setup.</p>
        <div class="callout">
            <strong>Invite context</strong>
            Requested by {{ $invite->requestedBy?->email ?: 'system' }}.
            Membership role: {{ $invite->membership?->role ?: 'n/a' }}.
            Expires: {{ optional($invite->expires_at)->format('Y-m-d H:i:s') ?: 'n/a' }}.
        </div>
        <div class="callout">
            <strong>What happens next</strong>
            After password setup the account signs in immediately. If policy requires MFA, enrollment starts before admin access is complete.
        </div>
        <div class="callout">
            <strong>First-login checklist</strong>
            1. Set a strong password. 2. Complete MFA if requested. 3. Keep recovery material only during the first one-time display.
        </div>
        <form method="POST" action="{{ route('admin.invites.accept', ['token' => $token]) }}">
            @csrf
            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="{{ old('name', $invite->user?->name) }}" required data-testid="invite-name">
            @error('name') <div class="error">{{ $message }}</div> @enderror

            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" data-testid="invite-password">
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label for="password_confirmation">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" data-testid="invite-password-confirmation">

            <button type="submit" class="button" data-testid="invite-accept">Accept Invite and Continue</button>
        </form>
    @endif
@endsection
