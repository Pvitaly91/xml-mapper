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
    <form method="POST" action="{{ route('admin.auth.reauth.password.store') }}">
        @csrf
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autofocus>
        @error('password') <div class="error">{{ $message }}</div> @enderror
        <button type="submit" class="button">Confirm Password</button>
    </form>
@endsection
