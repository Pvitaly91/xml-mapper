@extends('layouts.guest')

@section('content')
    <h1>MFA Challenge</h1>
    <p>Enter a current authenticator code or an unused recovery code to finish admin sign-in.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif
    @if($stepUp['action_summary'] ?? null)
        <div class="callout">
            <strong>Blocked action waiting on MFA</strong>
            {{ $stepUp['action_summary'] }}
        </div>
    @endif
    <div class="callout">
        <strong>Allowed inputs</strong>
        Use a 6-digit authenticator code or a saved one-time recovery code. Recovery codes are invalid after first use.
    </div>
    <div class="callout">
        <strong>Expected outcome</strong>
        Successful verification finishes login and refreshes the MFA freshness window for governed actions.
    </div>
    <form method="POST" action="{{ route('admin.auth.mfa.challenge.store') }}">
        @csrf
        <label for="code">Authenticator or Recovery Code</label>
        <input id="code" type="password" name="code" autocomplete="one-time-code" required autofocus data-testid="mfa-challenge-code">
        @error('code') <div class="error">{{ $message }}</div> @enderror
        <button type="submit" class="button" data-testid="mfa-challenge-submit">Verify MFA</button>
    </form>
@endsection
