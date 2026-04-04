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
        <p>Requested by: {{ $invite->requestedBy?->email ?: 'system' }}<br>Expires: {{ optional($invite->expires_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</p>
        <form method="POST" action="{{ route('admin.invites.accept', ['token' => $token]) }}">
            @csrf
            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="{{ old('name', $invite->user?->name) }}" required>
            @error('name') <div class="error">{{ $message }}</div> @enderror

            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="new-password">
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label for="password_confirmation">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">

            <button type="submit" class="button">Accept Invite</button>
        </form>
    @endif
@endsection
