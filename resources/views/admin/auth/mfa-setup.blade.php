@extends('layouts.guest')

@section('content')
    <h1>MFA Setup</h1>
    <p>Enroll a TOTP authenticator for admin access and store the recovery material before continuing.</p>
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

    @if($user->hasMfaEnabled())
        <div class="callout">
            <strong>MFA is active</strong>
            This account already has MFA enabled. Only newly issued recovery codes are shown below, and only once.
        </div>
    @else
        <div class="callout">
            <strong>Enrollment steps</strong>
            1. Scan the QR code or paste the secret into your authenticator.
            2. Enter a fresh 6-digit code below.
            3. Save the recovery codes immediately after activation.
        </div>

        <p>Authenticator secret:</p>
        <code data-testid="mfa-secret">{{ $setup['secret'] }}</code>

        @if($setup['provisioning_uri'])
            <p>Provisioning URI:</p>
            <code>{{ $setup['provisioning_uri'] }}</code>
        @endif

        @if($setup['qr_svg'])
            <div style="margin-bottom: 16px;">{!! $setup['qr_svg'] !!}</div>
        @endif

        <form method="POST" action="{{ route('admin.auth.mfa.enable') }}">
            @csrf
            <label for="code">Authenticator Code</label>
            <input id="code" type="password" name="code" autocomplete="one-time-code" required data-testid="mfa-setup-code">
            @error('code') <div class="error">{{ $message }}</div> @enderror
            <button type="submit" class="button" data-testid="mfa-enable-submit">Enable MFA</button>
        </form>
    @endif

    @if($recoveryCodes)
        <div class="callout">
            <strong>Recovery codes</strong>
            Store these codes securely now. They will not be shown again after you leave this screen.
        </div>
        <pre data-testid="mfa-recovery-codes">{{ implode(PHP_EOL, $recoveryCodes) }}</pre>
        <div class="actions">
            <a
                class="button secondary"
                download="xml-mapper-recovery-codes.txt"
                href="data:text/plain;charset=utf-8,{{ rawurlencode(implode(PHP_EOL, $recoveryCodes)) }}"
                data-testid="mfa-recovery-download"
            >Download recovery codes</a>
            <a class="button" href="{{ route('admin.dashboard') }}" data-testid="mfa-continue-dashboard">Continue to dashboard</a>
        </div>
    @elseif($user->hasMfaEnabled())
        <div class="actions">
            <a class="button" href="{{ route('admin.dashboard') }}" data-testid="mfa-already-enabled-dashboard">Continue to dashboard</a>
        </div>
    @endif
@endsection
