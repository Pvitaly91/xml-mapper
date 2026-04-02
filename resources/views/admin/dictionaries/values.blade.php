@extends('layouts.admin', ['title' => 'Kasta Attribute Values'])

@section('subtitle', 'Enumerated values used for value-level mapping approval.')

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
                <label for="kasta_attribute_id">Attribute</label>
                <select id="kasta_attribute_id" name="kasta_attribute_id">
                    <option value="">Any</option>
                    @foreach($attributes as $attribute)
                        <option value="{{ $attribute->id }}" @selected((string) ($filters['kasta_attribute_id'] ?? '') === (string) $attribute->id)>{{ $attribute->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="align-self: end;"><button class="button secondary" type="submit">Apply filters</button></div>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Category</th><th>Attribute</th><th>Value</th><th>External ID</th></tr></thead>
                <tbody>
                @forelse($values as $value)
                    <tr>
                        <td>{{ $value->kastaAttribute?->kastaCategory?->full_path ?: $value->kastaAttribute?->kastaCategory?->name ?: 'n/a' }}</td>
                        <td>{{ $value->kastaAttribute?->name ?: 'n/a' }}</td>
                        <td>{{ $value->value }}</td>
                        <td>{{ $value->external_id ?: 'n/a' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No values found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('components.admin.paginator', ['paginator' => $values])
    </section>
@endsection
