@extends('layouts.guest')

@section('content')
    <h1>MFA Setup</h1>
    <p>Enroll a TOTP authenticator for admin access.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif

    <p>Authenticator secret:</p>
    <code>{{ $setup['secret'] }}</code>

    <p>Provisioning URI:</p>
    <code>{{ $setup['provisioning_uri'] }}</code>

    @if($setup['qr_svg'])
        <div style="margin-bottom: 16px;">{!! $setup['qr_svg'] !!}</div>
    @endif

    @if($recoveryCodes)
        <p>Recovery codes. Store them securely now; they will not be shown again.</p>
        <pre>{{ implode(PHP_EOL, $recoveryCodes) }}</pre>
    @endif

    <form method="POST" action="{{ route('admin.auth.mfa.enable') }}">
        @csrf
        <label for="code">Authenticator Code</label>
        <input id="code" type="text" name="code" autocomplete="one-time-code" required>
        @error('code') <div class="error">{{ $message }}</div> @enderror
        <button type="submit" class="button">Enable MFA</button>
    </form>
@endsection
