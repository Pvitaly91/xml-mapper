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
    <form method="POST" action="{{ route('admin.auth.reauth.mfa.store') }}">
        @csrf
        <label for="code">Authenticator or Recovery Code</label>
        <input id="code" type="text" name="code" required autofocus autocomplete="one-time-code">
        @error('code') <div class="error">{{ $message }}</div> @enderror
        <button type="submit" class="button">Confirm MFA</button>
    </form>
@endsection
