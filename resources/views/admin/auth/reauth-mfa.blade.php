@extends('layouts.guest')

@section('content')
    <h1>MFA Re-Authentication</h1>
    <p>Confirm a current authenticator or recovery code for a sensitive action.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif
    <div class="callout">
        <strong>Why this appears</strong>
        {{ $stepUp['action_summary'] ?? 'The action needs a fresh MFA confirmation before it can continue.' }}
    </div>
    <div class="callout">
        <strong>Allowed inputs</strong>
        Use a current authenticator code or an unused recovery code. After success the workflow returns to the blocked screen.
    </div>
    <form method="POST" action="{{ route('admin.auth.reauth.mfa.store') }}">
        @csrf
        <label for="code">Authenticator or Recovery Code</label>
        <input id="code" type="password" name="code" required autofocus autocomplete="one-time-code" data-testid="reauth-mfa-input">
        @error('code') <div class="error">{{ $message }}</div> @enderror
        <button type="submit" class="button" data-testid="reauth-mfa-submit">Confirm MFA</button>
    </form>
@endsection
