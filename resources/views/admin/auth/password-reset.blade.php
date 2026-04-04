@extends('layouts.guest')

@section('content')
    <h1>Password Reset Required</h1>
    <p>Update the admin password before continuing.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="message" style="background:#ffe7e7;border-color:#f2b3b3;color:#8d1a1a;">{{ session('error') }}</div>
    @endif
    <form method="POST" action="{{ route('admin.auth.password-reset.update') }}">
        @csrf
        @method('PUT')
        <label for="current_password">Current Password</label>
        <input id="current_password" type="password" name="current_password" required>
        @error('current_password') <div class="error">{{ $message }}</div> @enderror

        <label for="password">New Password</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <label for="password_confirmation">Confirm New Password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

        <button type="submit" class="button">Update Password</button>
    </form>
@endsection
