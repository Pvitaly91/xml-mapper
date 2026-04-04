@extends('layouts.guest')

@section('content')
    <h1>MFA Challenge</h1>
    <p>Enter a current authenticator code or an unused recovery code.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif
    <form method="POST" action="{{ route('admin.auth.mfa.challenge.store') }}">
        @csrf
        <label for="code">Authenticator or Recovery Code</label>
        <input id="code" type="text" name="code" autocomplete="one-time-code" required autofocus>
        @error('code') <div class="error">{{ $message }}</div> @enderror
        <button type="submit" class="button">Verify MFA</button>
    </form>
@endsection
