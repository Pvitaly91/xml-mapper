@extends('layouts.admin', ['title' => 'Dictionary Imports'])

@section('subtitle', 'File-driven imports, dry-run previews and reimport history for Kasta dictionaries.')

@section('content')
    <section class="panel">
        <h2>Run Import</h2>
        <form method="POST" action="{{ route('admin.dictionary-imports.store') }}" enctype="multipart/form-data" class="form-grid">
            @csrf
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type" required>
                    @foreach($types as $type)
                        <option value="{{ $type }}" @selected(old('type') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="format">Format</label>
                <select id="format" name="format">
                    <option value="">Auto-detect</option>
                    <option value="json" @selected(old('format') === 'json')>json</option>
                    <option value="csv" @selected(old('format') === 'csv')>csv</option>
                </select>
            </div>
            <div class="field">
                <label for="file">Upload file</label>
                <input id="file" type="file" name="file">
            </div>
            <div class="field">
                <label for="path">Or absolute path</label>
                <input id="path" name="path" value="{{ old('path') }}" placeholder="{{ config('feed_mediator.kasta_dictionary_sample_path') }}">
            </div>
            <div class="field full">
                <div class="checks">
                    <label class="check"><input type="checkbox" name="dry_run" value="1" @checked(old('dry_run'))> Dry-run preview</label>
                    <label class="check"><input type="checkbox" name="deactivate_missing" value="1" @checked(old('deactivate_missing'))> Deactivate missing</label>
                </div>
            </div>
            <div class="full toolbar">
                <button class="button" type="submit">Run import</button>
                <span class="muted">If no file/path is provided, the sample fixture for the selected type is used.</span>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Reimport Latest</h2>
        <form method="POST" action="{{ route('admin.dictionary-imports.reimport') }}" class="toolbar">
            @csrf
            <div class="field" style="min-width: 260px;">
                <label for="reimport_type">Type</label>
                <select id="reimport_type" name="type" required>
                    @foreach($types as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <label class="check"><input type="checkbox" name="dry_run" value="1"> Dry-run</label>
            <label class="check"><input type="checkbox" name="deactivate_missing" value="1"> Deactivate missing</label>
            <button class="button secondary" type="submit">Reimport latest</button>
        </form>
    </section>

    <section class="panel">
        <h2>History</h2>
        <form method="GET" class="filters">
            <div class="field">
                <label for="filter_type">Type</label>
                <select id="filter_type" name="type">
                    <option value="">All</option>
                    @foreach($types as $type)
                        <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_status">Status</label>
                <select id="filter_status" name="status">
                    <option value="">All</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="date_from">Date from</label>
                <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="field">
                <label for="date_to">Date to</label>
                <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Format</th><th>Rows</th><th>Created / updated</th><th>Started</th><th></th></tr></thead>
                <tbody>
                @forelse($imports as $import)
                    <tr>
                        <td>#{{ $import->id }}</td>
                        <td>{{ $import->type }}</td>
                        <td>{{ $import->status }}{{ $import->dry_run ? ' (dry-run)' : '' }}</td>
                        <td>{{ $import->source_format }}</td>
                        <td>{{ $import->rows_total }}</td>
                        <td>{{ $import->created_count }} / {{ $import->updated_count }}</td>
                        <td>{{ optional($import->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td><a class="button link" href="{{ route('admin.dictionary-imports.show', $import) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No dictionary imports found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-admin.paginator :paginator="$imports" />
    </section>
@endsection
