@extends('layouts.admin', ['title' => 'Category Mappings'])

@section('subtitle', 'Map source categories to Kasta categories with manual overrides and RZ-based automap.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="mapping_status">Mapping state</label>
                <select id="mapping_status" name="mapping_status">
                    <option value="">Any</option>
                    <option value="unmapped" @selected(($filters['mapping_status'] ?? '') === 'unmapped')>Unmapped</option>
                    <option value="mapped" @selected(($filters['mapping_status'] ?? '') === 'mapped')>Mapped</option>
                </select>
            </div>
            <div class="field">
                <label for="strategy">Strategy</label>
                <select id="strategy" name="strategy">
                    <option value="">Any</option>
                    <option value="rz_id" @selected(($filters['strategy'] ?? '') === 'rz_id')>Automapped by rz_id</option>
                    <option value="manual" @selected(($filters['strategy'] ?? '') === 'manual')>Manual</option>
                </select>
            </div>
            <div class="field">
                <label for="is_active">Active</label>
                <select id="is_active" name="is_active">
                    <option value="">Any</option>
                    <option value="1" @selected(($filters['is_active'] ?? '') === '1')>Active</option>
                    <option value="0" @selected(($filters['is_active'] ?? '') === '0')>Inactive</option>
                </select>
            </div>
            <div class="field"><label for="source_search">Source category search</label><input id="source_search" name="source_search" value="{{ $filters['source_search'] ?? '' }}"></div>
            <div class="field"><label for="kasta_search">Kasta category search</label><input id="kasta_search" name="kasta_search" value="{{ $filters['kasta_search'] ?? '' }}"></div>
            <div class="field" style="align-self: end;"><button class="button secondary" type="submit">Apply filters</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>{{ $selectedMapping ? 'Edit mapping' : 'Create manual mapping' }}</h2>
        <form method="POST" action="{{ $selectedMapping ? route('admin.feed-profiles.category-mappings.update', [$feedProfile, $selectedMapping]) : route('admin.feed-profiles.category-mappings.store', $feedProfile) }}">
            @csrf
            @if($selectedMapping)
                @method('PUT')
            @endif
            <div class="form-grid">
                <div class="field">
                    <label for="source_category_id">Source category</label>
                    <select id="source_category_id" name="source_category_id" required>
                        @foreach($sourceCategories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('source_category_id', $selectedMapping?->source_category_id) === (string) $category->id)>{{ $category->full_path ?: $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="kasta_category_id">Kasta category</label>
                    <select id="kasta_category_id" name="kasta_category_id" required>
                        @foreach($kastaCategories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('kasta_category_id', $selectedMapping?->kasta_category_id) === (string) $category->id)>{{ $category->full_path ?: $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field full">
                    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $selectedMapping?->is_active ?? true))> Active mapping</label>
                </div>
            </div>
            <div class="toolbar" style="margin-top: 16px;">
                <button class="button" type="submit">{{ $selectedMapping ? 'Save mapping' : 'Create mapping' }}</button>
                @if($selectedMapping)
                    <a class="button secondary" href="{{ route('admin.feed-profiles.category-mappings.index', $feedProfile) }}">Cancel edit</a>
                @endif
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="toolbar">
            <form method="POST" action="{{ route('admin.feed-profiles.category-mappings.automap', $feedProfile) }}">
                @csrf
                <button class="button" type="submit">Run automap for all</button>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.feed-profiles.category-mappings.automap', $feedProfile) }}">
            @csrf
            <div class="toolbar">
                <button class="button secondary" type="submit">Automap selected</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th></th>
                        <th>Source category</th>
                        <th>RZ ID</th>
                        <th>Kasta category</th>
                        <th>Strategy</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td><input type="checkbox" name="source_category_ids[]" value="{{ $row->id }}"></td>
                            <td>{{ $row->full_path ?: $row->name }}</td>
                            <td>{{ $row->rz_id ?: 'n/a' }}</td>
                            <td>{{ $row->kasta_category_path ?: $row->kasta_category_name ?: 'Unmapped' }}</td>
                            <td>{{ $row->mapping_strategy ?: 'n/a' }}</td>
                            <td>{{ $row->mapping_id ? ($row->mapping_is_active ? 'active' : 'inactive') : 'unmapped' }}</td>
                            <td>
                                <div class="toolbar">
                                    @if($row->mapping_id)
                                        <a class="button link" href="{{ route('admin.feed-profiles.category-mappings.index', ['feed_profile' => $feedProfile, 'edit' => $row->mapping_id]) }}">Edit</a>
                                        <form method="POST" action="{{ route('admin.feed-profiles.category-mappings.deactivate', [$feedProfile, $row->mapping_id]) }}">
                                            @csrf
                                            <button class="button warning" type="submit">Deactivate</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.feed-profiles.category-mappings.destroy', [$feedProfile, $row->mapping_id]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button danger" type="submit">Delete</button>
                                        </form>
                                    @else
                                        <span class="muted">No actions</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">No source categories found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </form>

        @include('components.admin.paginator', ['paginator' => $rows])
    </section>
@endsection
