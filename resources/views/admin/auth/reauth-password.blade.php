@extends('layouts.guest')

@section('content')
    <h1>Password Re-Authentication</h1>
    <p>Confirm the current password before continuing with a sensitive action.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif
    <div class="callout">
        <strong>Why this appears</strong>
        {{ $stepUp['action_summary'] ?? 'The current session is no longer fresh enough for the requested action.' }}
    </div>
    <div class="callout">
        <strong>What happens after success</strong>
        The screen returns to the blocked workflow so you can submit the action again without starting over.
    </div>
    <form method="POST" action="{{ route('admin.auth.reauth.password.store') }}">
        @csrf
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autofocus data-testid="reauth-password-input">
        @error('password') <div class="error">{{ $message }}</div> @enderror
        <button type="submit" class="button" data-testid="reauth-password-submit">Confirm Password</button>
    </form>
@endsection
