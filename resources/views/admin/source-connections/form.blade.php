@extends('layouts.admin', ['title' => $pageTitle])

@section('subtitle', 'Driver, URL, sync interval and settings for Prom imports.')

@section('content')
    <section class="panel">
        <form method="POST" action="{{ $connection->exists ? route('admin.source-connections.update', $connection) : route('admin.source-connections.store') }}">
            @csrf
            @if($connection->exists)
                @method('PUT')
            @endif

            <div class="form-grid">
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" value="{{ old('name', $connection->name) }}" required>
                </div>
                <div class="field">
                    <label for="code">Code</label>
                    <input id="code" name="code" value="{{ old('code', $connection->code) }}" required>
                </div>
                <div class="field">
                    <label for="driver">Driver</label>
                    <select id="driver" name="driver">
                        <option value="prom_yml" @selected(old('driver', $connection->driver ?: 'prom_yml') === 'prom_yml')>prom_yml</option>
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="active" @selected(old('status', $connection->status ?: 'active') === 'active')>active</option>
                        <option value="paused" @selected(old('status', $connection->status) === 'paused')>paused</option>
                    </select>
                </div>
                <div class="field full">
                    <label for="source_url">Source URL or local path</label>
                    <input id="source_url" name="source_url" value="{{ old('source_url', $connection->source_url) }}" placeholder="https://example.com/feed.xml or C:\feeds\prom.xml">
                </div>
                <div class="field">
                    <label for="sync_interval_minutes">Sync interval, minutes</label>
                    <input id="sync_interval_minutes" type="number" min="1" name="sync_interval_minutes" value="{{ old('sync_interval_minutes', $connection->sync_interval_minutes ?: 60) }}" required>
                </div>
                <div class="field full">
                    <label for="credentials_json">Credentials JSON</label>
                    <textarea id="credentials_json" name="credentials_json">{{ old('credentials_json', $connection->credentials ? json_encode($connection->credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                </div>
                <div class="field full">
                    <label for="options_json">Settings / options JSON</label>
                    <textarea id="options_json" name="options_json">{{ old('options_json', $connection->options ? json_encode($connection->options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                </div>
            </div>

            <div class="toolbar" style="margin-top: 18px;">
                <button type="submit" class="button">{{ $connection->exists ? 'Save changes' : 'Create connection' }}</button>
                <a class="button secondary" href="{{ route('admin.source-connections.index') }}">Back</a>
            </div>
        </form>
    </section>
@endsection
