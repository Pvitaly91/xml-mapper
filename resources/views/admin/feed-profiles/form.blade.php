@extends('layouts.admin', ['title' => $pageTitle])

@section('subtitle', 'Profile-level feed configuration, Kasta export guardrails, and publication behaviour.')

@section('content')
    @php($exportSettings = $feedProfile->exportSettings())

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
                <div class="field">
                    <label for="minimum_pictures">Minimum pictures</label>
                    <input id="minimum_pictures" type="number" min="1" name="minimum_pictures" value="{{ old('minimum_pictures', $exportSettings['minimum_pictures'] ?? 1) }}">
                </div>
                <div class="field">
                    <label for="minimum_price_threshold">Minimum price threshold</label>
                    <input id="minimum_price_threshold" type="number" min="0" step="0.01" name="minimum_price_threshold" value="{{ old('minimum_price_threshold', $exportSettings['minimum_price_threshold'] ?? '') }}">
                </div>
                <div class="field">
                    <label for="override_minimum_pictures">Override minimum pictures</label>
                    <input id="override_minimum_pictures" type="number" min="1" name="override_minimum_pictures" value="{{ old('override_minimum_pictures', $exportSettings['override_minimum_pictures'] ?? '') }}">
                </div>
                <div class="field">
                    <label for="minimum_ready_items">Minimum ready items</label>
                    <input id="minimum_ready_items" type="number" min="0" name="minimum_ready_items" value="{{ old('minimum_ready_items', $exportSettings['minimum_ready_items'] ?? 0) }}">
                </div>
                <div class="field">
                    <label for="maximum_invalid_ratio">Maximum invalid ratio</label>
                    <input id="maximum_invalid_ratio" type="number" min="0" max="1" step="0.01" name="maximum_invalid_ratio" value="{{ old('maximum_invalid_ratio', $exportSettings['maximum_invalid_ratio'] ?? 1) }}">
                </div>
                <div class="field">
                    <label for="required_signoff_status">Required sign-off status</label>
                    <select id="required_signoff_status" name="required_signoff_status">
                        @foreach(['internal_approved', 'client_review', 'client_approved'] as $signoffStatus)
                            <option value="{{ $signoffStatus }}" @selected(old('required_signoff_status', $exportSettings['required_signoff_status'] ?? 'internal_approved') === $signoffStatus)>{{ $signoffStatus }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="publish_window_timezone">Publish window timezone</label>
                    <input id="publish_window_timezone" name="publish_window_timezone" value="{{ old('publish_window_timezone', $exportSettings['publish_window_timezone'] ?? ($feedProfile->shop?->timezone ?? config('app.timezone'))) }}">
                </div>
                <div class="field">
                    <label for="publish_window_start">Publish window start</label>
                    <input id="publish_window_start" name="publish_window_start" value="{{ old('publish_window_start', $exportSettings['publish_window_start'] ?? '09:00') }}">
                </div>
                <div class="field">
                    <label for="publish_window_end">Publish window end</label>
                    <input id="publish_window_end" name="publish_window_end" value="{{ old('publish_window_end', $exportSettings['publish_window_end'] ?? '18:00') }}">
                </div>
                <div class="field full">
                    <label>Allowed publish weekdays</label>
                    <div class="checks">
                        @foreach(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day)
                            <label class="check">
                                <input type="checkbox" name="publish_window_days[]" value="{{ $day }}" @checked(in_array($day, old('publish_window_days', $exportSettings['publish_window_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri']), true))>
                                {{ $day }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="field full">
                    <label for="excluded_source_category_ids_text">Excluded source category IDs</label>
                    <textarea id="excluded_source_category_ids_text" name="excluded_source_category_ids_text" rows="3" placeholder="One ID per line">{{ old('excluded_source_category_ids_text', implode(PHP_EOL, $exportSettings['excluded_source_category_ids'] ?? [])) }}</textarea>
                </div>
                <div class="field full">
                    <label for="excluded_vendors_text">Excluded vendors / brands</label>
                    <textarea id="excluded_vendors_text" name="excluded_vendors_text" rows="3" placeholder="One vendor per line">{{ old('excluded_vendors_text', implode(PHP_EOL, $exportSettings['excluded_vendors'] ?? [])) }}</textarea>
                </div>
                <div class="field full">
                    <label for="disabled_export_category_ids_text">Disabled Kasta category IDs</label>
                    <textarea id="disabled_export_category_ids_text" name="disabled_export_category_ids_text" rows="3" placeholder="One category external ID per line">{{ old('disabled_export_category_ids_text', implode(PHP_EOL, $exportSettings['disabled_export_category_ids'] ?? [])) }}</textarea>
                </div>
                <div class="field full">
                    <label for="forced_attribute_overrides_json">Forced attribute overrides JSON</label>
                    <textarea id="forced_attribute_overrides_json" name="forced_attribute_overrides_json" rows="5" placeholder='{"color":"Black"}'>{{ old('forced_attribute_overrides_json', !empty($exportSettings['forced_attribute_overrides']) ? json_encode($exportSettings['forced_attribute_overrides'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                </div>
                <div class="field full">
                    <label for="forced_value_overrides_json">Forced value overrides JSON</label>
                    <textarea id="forced_value_overrides_json" name="forced_value_overrides_json" rows="5" placeholder='{"темно-синий":"Navy"}'>{{ old('forced_value_overrides_json', !empty($exportSettings['forced_value_overrides']) ? json_encode($exportSettings['forced_value_overrides'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                </div>
                <div class="field full">
                    <label>Flags</label>
                    <div class="checks">
                        <label class="check"><input type="checkbox" name="include_unavailable" value="1" @checked(old('include_unavailable', $feedProfile->include_unavailable))> Include unavailable</label>
                        <label class="check"><input type="checkbox" name="auto_sync" value="1" @checked(old('auto_sync', $feedProfile->auto_sync))> Auto sync</label>
                        <label class="check"><input type="checkbox" name="auto_build" value="1" @checked(old('auto_build', $feedProfile->auto_build))> Auto build</label>
                        <label class="check"><input type="checkbox" name="publish_guard_enabled" value="1" @checked(old('publish_guard_enabled', $exportSettings['publish_guard_enabled'] ?? false))> Enable publish guard</label>
                        <label class="check"><input type="checkbox" name="block_publish_on_critical_conformance" value="1" @checked(old('block_publish_on_critical_conformance', $exportSettings['block_publish_on_critical_conformance'] ?? true))> Block on critical conformance errors</label>
                        <label class="check"><input type="checkbox" name="signoff_required" value="1" @checked(old('signoff_required', $exportSettings['signoff_required'] ?? false))> Require sign-off before publish</label>
                        <label class="check"><input type="checkbox" name="publish_window_enabled" value="1" @checked(old('publish_window_enabled', $exportSettings['publish_window_enabled'] ?? false))> Enable publish window</label>
                        <label class="check"><input type="checkbox" name="freeze_mode" value="1" @checked(old('freeze_mode', $exportSettings['freeze_mode'] ?? false))> Freeze mode active</label>
                    </div>
                </div>
                <div class="field full">
                    <label for="settings_json">Advanced settings JSON</label>
                    <textarea id="settings_json" name="settings_json">{{ old('settings_json', $feedProfile->settings ? json_encode($feedProfile->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                </div>
            </div>

            <div class="toolbar" style="margin-top: 18px;">
                <button type="submit" class="button">{{ $feedProfile->exists ? 'Save changes' : 'Create feed profile' }}</button>
                <a class="button secondary" href="{{ route('admin.feed-profiles.index') }}">Back</a>
            </div>
        </form>
    </section>
@endsection
