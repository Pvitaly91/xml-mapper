@extends('layouts.admin', ['title' => $pageTitle])

@section('subtitle', 'Profile-level feed configuration and publication behaviour.')

@section('content')
    <section class="panel">
        <form method="POST" action="{{ $feedProfile->exists ? route('admin.feed-profiles.update', $feedProfile) : route('admin.feed-profiles.store') }}">
            @csrf
            @if($feedProfile->exists)
                @method('PUT')
            @endif

            <div class="form-grid">
                <div class="field">
                    <label for="source_connection_id">Source connection</label>
                    <select id="source_connection_id" name="source_connection_id" required>
                        @foreach($sourceConnections as $connection)
                            <option value="{{ $connection->id }}" @selected((string) old('source_connection_id', $feedProfile->source_connection_id) === (string) $connection->id)>{{ $connection->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="draft" @selected(old('status', $feedProfile->status ?: 'draft') === 'draft')>draft</option>
                        <option value="active" @selected(old('status', $feedProfile->status) === 'active')>active</option>
                        <option value="inactive" @selected(old('status', $feedProfile->status) === 'inactive')>inactive</option>
                    </select>
                </div>
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" value="{{ old('name', $feedProfile->name) }}" required>
                </div>
                <div class="field">
                    <label for="code">Code</label>
                    <input id="code" name="code" value="{{ old('code', $feedProfile->code) }}" required>
                </div>
                <div class="field">
                    <label for="currency">Currency</label>
                    <input id="currency" name="currency" value="{{ old('currency', $feedProfile->currency ?: 'UAH') }}" required>
                </div>
                <div class="field">
                    <label for="language">Language</label>
                    <input id="language" name="language" value="{{ old('language', $feedProfile->language ?: 'uk') }}" required>
                </div>
                <div class="field">
                    <label for="build_interval_minutes">Build interval, minutes</label>
                    <input id="build_interval_minutes" type="number" min="1" name="build_interval_minutes" value="{{ old('build_interval_minutes', $feedProfile->build_interval_minutes ?: 60) }}" required>
                </div>
                <div class="field full">
                    <label for="settings_json">Settings JSON</label>
                    <textarea id="settings_json" name="settings_json">{{ old('settings_json', $feedProfile->settings ? json_encode($feedProfile->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                </div>
                <div class="field full">
                    <label>Flags</label>
                    <div class="checks">
                        <label class="check"><input type="checkbox" name="include_unavailable" value="1" @checked(old('include_unavailable', $feedProfile->include_unavailable))> Include unavailable</label>
                        <label class="check"><input type="checkbox" name="auto_sync" value="1" @checked(old('auto_sync', $feedProfile->auto_sync))> Auto sync</label>
                        <label class="check"><input type="checkbox" name="auto_build" value="1" @checked(old('auto_build', $feedProfile->auto_build))> Auto build</label>
                    </div>
                </div>
            </div>

            <div class="toolbar" style="margin-top: 18px;">
                <button type="submit" class="button">{{ $feedProfile->exists ? 'Save changes' : 'Create feed profile' }}</button>
                <a class="button secondary" href="{{ route('admin.feed-profiles.index') }}">Back</a>
            </div>
        </form>
    </section>
@endsection
