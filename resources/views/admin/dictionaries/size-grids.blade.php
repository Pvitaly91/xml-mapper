@extends('layouts.admin', ['title' => 'Size Grids'])

@section('subtitle', 'Shared and shop-level size grid definitions imported from stubs or external files.')

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
                <thead><tr><th>Code</th><th>Name</th><th>Scope</th><th>Status</th><th>Schema</th></tr></thead>
                <tbody>
                @forelse($sizeGrids as $sizeGrid)
                    <tr>
                        <td>{{ $sizeGrid->code }}</td>
                        <td>{{ $sizeGrid->name }}</td>
                        <td>{{ $sizeGrid->shop_id ? 'Shop specific' : 'Global' }}</td>
                        <td>{{ $sizeGrid->is_active ? 'active' : 'inactive' }}</td>
                        <td><pre>{{ json_encode($sizeGrid->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No size grids found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('components.admin.paginator', ['paginator' => $sizeGrids])
    </section>
@endsection
