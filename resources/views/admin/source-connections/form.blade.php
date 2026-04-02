@extends('layouts.admin', ['title' => $pageTitle])

@section('subtitle', 'Driver-specific source settings for Prom YML and Prom API imports.')

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
                    <select id="driver" name="driver" data-driver-select>
                        @foreach($driverOptions as $driver => $label)
                            <option value="{{ $driver }}" @selected(old('driver', $connection->driver ?: \App\Models\SourceConnection::DRIVER_PROM_YML) === $driver)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="active" @selected(old('status', $connection->status ?: 'active') === 'active')>active</option>
                        <option value="paused" @selected(old('status', $connection->status) === 'paused')>paused</option>
                    </select>
                </div>
                <div class="field full" data-driver-section="prom_yml">
                    <label for="source_url">Source URL or local path</label>
                    <input id="source_url" name="source_url" value="{{ old('source_url', $connection->source_url) }}" placeholder="https://example.com/feed.xml or C:\feeds\prom.xml">
                </div>
                <div class="field full" data-driver-section="prom_api">
                    <label for="api_base_url">API base URL</label>
                    <input id="api_base_url" name="api_base_url" value="{{ old('api_base_url', $connection->api_base_url ?: \App\Models\SourceConnection::defaultPromApiBaseUrl()) }}" placeholder="https://my.prom.ua">
                </div>
                <div class="field" data-driver-section="prom_api">
                    <label for="api_version">API version</label>
                    <input id="api_version" name="api_version" value="{{ old('api_version', $connection->api_version ?: \App\Models\SourceConnection::defaultPromApiVersion()) }}" placeholder="v1">
                </div>
                <div class="field full" data-driver-section="prom_api">
                    <label for="api_token">API token</label>
                    <input id="api_token" name="api_token" type="password" value="" placeholder="{{ $connection->exists && $connection->api_token ? 'Leave blank to keep the current token' : 'Paste Prom API token' }}">
                    @if($connection->exists && $connection->api_token)
                        <p class="muted" style="margin-top: 6px;">Stored token: {{ $connection->maskedApiToken() }}</p>
                    @endif
                </div>
                <div class="field">
                    <label for="sync_interval_minutes">Sync interval, minutes</label>
                    <input id="sync_interval_minutes" type="number" min="1" name="sync_interval_minutes" value="{{ old('sync_interval_minutes', $connection->sync_interval_minutes ?: 60) }}" required>
                </div>
                <div class="field full" data-driver-section="prom_yml">
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

    <script>
        (() => {
            const select = document.querySelector('[data-driver-select]');
            const sections = document.querySelectorAll('[data-driver-section]');

            if (!select || sections.length === 0) {
                return;
            }

            const toggle = () => {
                const driver = select.value;

                sections.forEach((section) => {
                    const matches = section.getAttribute('data-driver-section') === driver;
                    section.style.display = matches ? '' : 'none';
                });
            };

            select.addEventListener('change', toggle);
            toggle();
        })();
    </script>
@endsection
