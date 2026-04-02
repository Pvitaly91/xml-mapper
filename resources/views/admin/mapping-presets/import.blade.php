@extends('layouts.admin', ['title' => 'Mapping Presets'])

@section('subtitle', 'Export mappings from a ready profile and import them into a similar shop with dry-run preview and collision handling.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button" href="{{ route('admin.feed-profiles.mapping-presets.export', $feedProfile) }}">Download preset JSON</a>
        </div>
    </section>

    <section class="panel">
        <form method="POST" action="{{ route('admin.feed-profiles.mapping-presets.preview', $feedProfile) }}">
            @csrf
            <div class="form-grid">
                <div class="field full">
                    <label for="preset_json">Preset JSON</label>
                    <textarea id="preset_json" name="preset_json" required>{{ old('preset_json', $presetJson) }}</textarea>
                </div>
                <div class="field">
                    <label for="collision_strategy">Collision strategy</label>
                    <select id="collision_strategy" name="collision_strategy">
                        <option value="skip_existing" @selected(($collisionStrategy ?? 'skip_existing') === 'skip_existing')>Skip existing</option>
                        <option value="overwrite_existing" @selected(($collisionStrategy ?? '') === 'overwrite_existing')>Overwrite existing</option>
                        <option value="merge_if_safe" @selected(($collisionStrategy ?? '') === 'merge_if_safe')>Merge if safe</option>
                    </select>
                </div>
            </div>
            <div class="toolbar" style="margin-top: 16px;">
                <button class="button" type="submit">Preview import</button>
            </div>
        </form>
    </section>

    @if($preview)
        <section class="panel">
            <h2>Dry-run Preview</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Section</th>
                        <th>Create</th>
                        <th>Update</th>
                        <th>Skip</th>
                        <th>Collisions</th>
                        <th>Unresolved</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($preview['summary'] as $section => $summary)
                        <tr>
                            <td>{{ str_replace('_', ' ', $section) }}</td>
                            <td>{{ $summary['create'] }}</td>
                            <td>{{ $summary['update'] }}</td>
                            <td>{{ $summary['skip'] }}</td>
                            <td>{{ $summary['collisions'] }}</td>
                            <td>{{ $summary['unresolved'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('admin.feed-profiles.mapping-presets.store', $feedProfile) }}" style="margin-top: 18px;">
                @csrf
                <input type="hidden" name="preset_json" value="{{ $presetJson }}">
                <input type="hidden" name="collision_strategy" value="{{ $collisionStrategy }}">
                <button class="button" type="submit">Import preset</button>
            </form>
        </section>
    @endif
@endsection
