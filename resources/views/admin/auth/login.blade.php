@extends('layouts.guest')

@section('content')
    <h1>Admin Login</h1>
    <p>Sign in to manage source imports, feed profiles, mappings and cached feed builds.</p>
    @if(session('status'))
        <div class="message">{{ session('status') }}</div>
    @endif
    <form method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        @error('email') <div class="error">{{ $message }}</div> @enderror
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>
        @error('password') <div class="error">{{ $message }}</div> @enderror
        <label class="check"><input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}> Keep session on this device</label>
        <button type="submit" class="button">Sign in</button>
    </form>
@endsection
