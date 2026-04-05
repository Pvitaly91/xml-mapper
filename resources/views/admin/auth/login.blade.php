@extends('layouts.guest')

@section('content')
    <h1>Admin Login</h1>
    <p>Sign in to manage source imports, feed profiles, release operations, approvals, and live support workflows.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif
    <div class="callout">
        <strong>Security checkpoints</strong>
        Password reset, MFA enrollment, MFA challenge, and step-up confirmation can all appear before dashboard access is complete.
    </div>
    <div class="callout">
        <strong>If access is blocked</strong>
        Suspended or locked accounts stop here. Password-reset-required accounts continue to a forced reset screen before the dashboard opens.
    </div>
    <form method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus data-testid="login-email">
        @error('email') <div class="error">{{ $message }}</div> @enderror
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required data-testid="login-password">
        @error('password') <div class="error">{{ $message }}</div> @enderror
        <label class="check"><input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}> Keep session on this device</label>
        <button type="submit" class="button" data-testid="login-submit">Sign in</button>
    </form>
@endsection
