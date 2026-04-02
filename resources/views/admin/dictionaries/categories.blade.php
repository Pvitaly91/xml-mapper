@extends('layouts.admin', ['title' => 'Kasta Categories'])

@section('subtitle', 'Reference categories available for manual mapping and RZ-based automap.')

@section('content')
    <section class="panel">
        <form method="GET" class="filters">
            <div class="field"><label for="search">Search</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}"></div>
            <div class="field">
                <label for="is_active">Active</label>
                <select id="is_active" name="is_active">
                    <option value="">Any</option>
                    <option value="1" @selected(($filters['is_active'] ?? '') === '1')>Active</option>
                    <option value="0" @selected(($filters['is_active'] ?? '') === '0')>Inactive</option>
                </select>
            </div>
            <div class="field" style="align-self: end;"><button class="button secondary" type="submit">Apply filters</button></div>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Name</th><th>Full path</th><th>RZ ID</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td>{{ $category->external_id }}</td>
                        <td>{{ $category->name }}</td>
                        <td>{{ $category->full_path ?: 'n/a' }}</td>
                        <td>{{ $category->rz_id ?: 'n/a' }}</td>
                        <td>{{ $category->is_active ? 'active' : 'inactive' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No categories found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('components.admin.paginator', ['paginator' => $categories])
    </section>
@endsection
