@extends('layouts.admin', ['title' => 'Kasta Dictionaries'])

@section('subtitle', 'Import reference data and inspect category, attribute, value and size-grid dictionaries.')

@section('content')
    <section class="panel">
        <form method="POST" action="{{ route('admin.dictionaries.import') }}" class="toolbar">
            @csrf
            <div class="field" style="min-width: 320px;">
                <label for="path">Optional legacy bundle path</label>
                <input id="path" name="path" value="{{ old('path') }}" placeholder="{{ config('feed_mediator.kasta_dictionary_stub_path') }}">
            </div>
            <button class="button" type="submit">Import sample bundle</button>
            <a class="button secondary" href="{{ route('admin.dictionary-imports.index') }}">Open import history</a>
        </form>
        <p class="muted">Legacy sample bundle path: <code>{{ config('feed_mediator.kasta_dictionary_stub_path') }}</code></p>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Categories</span><strong>{{ $counts['categories'] }}</strong></div>
        <div class="stat"><span class="muted">Attributes</span><strong>{{ $counts['attributes'] }}</strong></div>
        <div class="stat"><span class="muted">Attribute values</span><strong>{{ $counts['attribute_values'] }}</strong></div>
        <div class="stat"><span class="muted">Size grids</span><strong>{{ $counts['size_grids'] }}</strong></div>
    </div>

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.dictionaries.categories') }}">Categories</a>
            <a class="button secondary" href="{{ route('admin.dictionaries.attributes') }}">Attributes</a>
            <a class="button secondary" href="{{ route('admin.dictionaries.values') }}">Attribute values</a>
            <a class="button secondary" href="{{ route('admin.dictionaries.size-grids') }}">Size grids</a>
        </div>
        <h2>Recent Categories</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>External ID</th><th>Name</th><th>Path</th><th>RZ ID</th></tr></thead>
                <tbody>
                @forelse($recentCategories as $category)
                    <tr>
                        <td>{{ $category->external_id }}</td>
                        <td>{{ $category->name }}</td>
                        <td>{{ $category->full_path }}</td>
                        <td>{{ $category->rz_id ?: 'n/a' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Dictionary is empty.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Recent Imports</h2>
            <a class="button secondary" href="{{ route('admin.dictionary-imports.index') }}">View all imports</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Format</th><th>Rows</th><th>Started</th><th></th></tr></thead>
                <tbody>
                @forelse($recentImports as $import)
                    <tr>
                        <td>#{{ $import->id }}</td>
                        <td>{{ $import->type }}</td>
                        <td>{{ $import->status }}</td>
                        <td>{{ $import->source_format }}</td>
                        <td>{{ $import->rows_total }}</td>
                        <td>{{ optional($import->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td><a class="button link" href="{{ route('admin.dictionary-imports.show', $import) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No dictionary imports yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
