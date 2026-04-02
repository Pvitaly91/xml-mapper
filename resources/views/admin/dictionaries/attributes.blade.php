@extends('layouts.admin', ['title' => 'Kasta Attributes'])

@section('subtitle', 'Required and optional attributes grouped by Kasta category.')

@section('content')
    <section class="panel">
        <form method="GET" class="filters">
            <div class="field"><label for="search">Search</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}"></div>
            <div class="field">
                <label for="kasta_category_id">Category</label>
                <select id="kasta_category_id" name="kasta_category_id">
                    <option value="">Any</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['kasta_category_id'] ?? '') === (string) $category->id)>{{ $category->full_path ?: $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="required_only">Required only</label>
                <select id="required_only" name="required_only">
                    <option value="">Any</option>
                    <option value="1" @selected(($filters['required_only'] ?? '') === '1')>Required</option>
                </select>
            </div>
            <div class="field" style="align-self: end;"><button class="button secondary" type="submit">Apply filters</button></div>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Category</th><th>Name</th><th>Code</th><th>Type</th><th>Required</th><th>Custom values</th></tr></thead>
                <tbody>
                @forelse($attributes as $attribute)
                    <tr>
                        <td>{{ $attribute->kastaCategory?->full_path ?: $attribute->kastaCategory?->name ?: 'n/a' }}</td>
                        <td>{{ $attribute->name }}</td>
                        <td>{{ $attribute->code }}</td>
                        <td>{{ $attribute->data_type }}</td>
                        <td>{{ $attribute->is_required ? 'Yes' : 'No' }}</td>
                        <td>{{ $attribute->allows_custom_value ? 'Yes' : 'No' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No attributes found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('components.admin.paginator', ['paginator' => $attributes])
    </section>
@endsection
