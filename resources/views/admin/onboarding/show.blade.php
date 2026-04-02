@extends('layouts.admin', ['title' => 'Shop Onboarding'])

@section('subtitle', 'Guided setup for the first real shop: source, dictionaries, sync, mappings, candidate build, and release handoff.')

@section('content')
    @php($selectedDriver = $summary['state']['selected_driver'] ?? $sourceConnection?->driver)
    <section class="panel">
        <div class="toolbar">
            <span class="badge {{ $summary['completed'] ? 'ok' : 'warn' }}">{{ $summary['completed'] ? 'completed' : 'in_progress' }}</span>
            <div class="muted">Current step: {{ str_replace('_', ' ', $summary['current_step']) }}</div>
            <form method="POST" action="{{ route('admin.onboarding.bootstrap') }}">
                @csrf
                <input type="hidden" name="run_sync" value="1">
                <input type="hidden" name="build_candidate" value="1">
                <button class="button secondary" type="submit">Run recommended bootstrap</button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Step</th>
                    <th>Status</th>
                    <th>Blocking reason</th>
                    <th>Next steps</th>
                </tr>
                </thead>
                <tbody>
                @foreach($summary['steps'] as $step)
                    <tr>
                        <td>{{ $step['label'] }}</td>
                        <td><span class="badge {{ $step['status'] === 'completed' ? 'ok' : ($step['status'] === 'current' ? 'warn' : '') }}">{{ $step['status'] }}</span></td>
                        <td>{{ $step['blocking_reason'] ?: 'n/a' }}</td>
                        <td>{{ implode(' ', $step['next_steps'] ?? []) ?: 'n/a' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>1. Shop</h2>
            <form method="POST" action="{{ route('admin.onboarding.shop') }}">
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="field">
                        <label for="name">Shop name</label>
                        <input id="name" name="name" value="{{ old('name', $shop?->name) }}" required>
                    </div>
                    <div class="field">
                        <label for="slug">Slug</label>
                        <input id="slug" name="slug" value="{{ old('slug', $shop?->slug) }}" required>
                    </div>
                    <div class="field">
                        <label for="currency">Currency</label>
                        <input id="currency" name="currency" value="{{ old('currency', $shop?->currency ?: 'UAH') }}" required>
                    </div>
                    <div class="field">
                        <label for="locale">Locale</label>
                        <input id="locale" name="locale" value="{{ old('locale', $shop?->locale ?: 'uk') }}" required>
                    </div>
                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <input id="timezone" name="timezone" value="{{ old('timezone', $shop?->timezone ?: 'Europe/Kiev') }}" required>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button" type="submit">Save shop</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>2. Source Driver</h2>
            <form method="POST" action="{{ route('admin.onboarding.source-driver') }}">
                @csrf
                <div class="field">
                    <label for="driver">Driver</label>
                    <select id="driver" name="driver">
                        @foreach(\App\Models\SourceConnection::driverOptions() as $driver => $label)
                            <option value="{{ $driver }}" @selected($selectedDriver === $driver)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button" type="submit">Save driver</button>
                    @if($selectedDriver)
                        <a class="button secondary" href="{{ route('admin.source-connections.create', ['driver' => $selectedDriver, 'redirect_to_onboarding' => 1]) }}">Configure source</a>
                    @endif
                </div>
            </form>

            @if($sourceConnection)
                <div class="detail-list" style="margin-top: 18px;">
                    <div class="detail-row"><strong>Current connection</strong><div>{{ $sourceConnection->name }} ({{ $sourceConnection->driver }})</div></div>
                    <div class="detail-row"><strong>Last connection check</strong><div>{{ $sourceConnection->last_connection_check_status ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Last sync</strong><div>{{ $sourceConnection->last_sync_status ?: 'n/a' }}</div></div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <a class="button link" href="{{ route('admin.source-connections.edit', ['source_connection' => $sourceConnection, 'redirect_to_onboarding' => 1]) }}">Edit connection</a>
                    <form method="POST" action="{{ route('admin.source-connections.test', $sourceConnection) }}">
                        @csrf
                        <button class="button secondary" type="submit">Test connection</button>
                    </form>
                    <form method="POST" action="{{ route('admin.source-connections.sync', $sourceConnection) }}">
                        @csrf
                        <button class="button secondary" type="submit">Run first sync</button>
                    </form>
                </div>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>3. Dictionaries and Feed Profile</h2>
            <div class="toolbar">
                <form method="POST" action="{{ route('admin.dictionaries.import') }}">
                    @csrf
                    <button class="button" type="submit">Import dictionaries</button>
                </form>
                <form method="POST" action="{{ route('admin.onboarding.feed-profile') }}">
                    @csrf
                    <button class="button secondary" type="submit">Create default feed profile</button>
                </form>
            </div>
            @if($feedProfile)
                <div class="detail-list" style="margin-top: 16px;">
                    <div class="detail-row"><strong>Feed profile</strong><div>{{ $feedProfile->name }} ({{ $feedProfile->code }})</div></div>
                    <div class="detail-row"><strong>Status</strong><div>{{ $feedProfile->status }}</div></div>
                </div>
            @endif
        </section>

        <section class="panel">
            <h2>4. Mapping Bootstrap</h2>
            <p class="muted">Run automap and suggestions after the first sync to seed category, attribute, and value mappings.</p>
            <div class="toolbar">
                <form method="POST" action="{{ route('admin.onboarding.mappings') }}">
                    @csrf
                    <button class="button" type="submit">Run automap and suggestions</button>
                </form>
                @if($feedProfile)
                    <a class="button secondary" href="{{ route('admin.feed-profiles.workbench.index', $feedProfile) }}">Open unresolved workbench</a>
                @endif
            </div>
        </section>
    </div>

    <section class="panel">
        <h2>5. Release Candidate and Handoff</h2>
        <div class="toolbar">
            <form method="POST" action="{{ route('admin.onboarding.candidate') }}">
                @csrf
                <button class="button" type="submit">Build first release candidate</button>
            </form>
            @if($feedProfile)
                <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Open release center</a>
                <a class="button secondary" href="{{ route('admin.shop-control.show') }}">Open go-live control panel</a>
            @endif
        </div>

        @if($latestGeneration)
            <div class="detail-list" style="margin-top: 16px;">
                <div class="detail-row"><strong>Latest generation</strong><div>#{{ $latestGeneration->id }}</div></div>
                <div class="detail-row"><strong>Release status</strong><div>{{ $latestGeneration->release_status }}</div></div>
                <div class="detail-row"><strong>Built at</strong><div>{{ optional($latestGeneration->built_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            </div>
        @endif
    </section>
@endsection
