@extends('layouts.admin', ['title' => 'Kasta Dictionaries'])

@section('subtitle', 'Import reference data and inspect category, attribute, value and size-grid dictionaries.')

@section('content')
    <section class="panel">
        <form method="POST" action="{{ route('admin.dictionaries.import') }}" class="toolbar">
            @csrf
            <div class="field" style="min-width: 320px;">
                <label for="path">Optional custom path</label>
                <input id="path" name="path" value="{{ old('path') }}" placeholder="{{ config('feed_mediator.kasta_dictionary_stub_path') }}">
            </div>
            <button class="button" type="submit">Import / reimport dictionaries</button>
        </form>
        <p class="muted">Default stub path: <code>{{ config('feed_mediator.kasta_dictionary_stub_path') }}</code></p>
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
@endsection
