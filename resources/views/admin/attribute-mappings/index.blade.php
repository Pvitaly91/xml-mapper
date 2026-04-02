@extends('layouts.admin', ['title' => 'Attribute Mappings'])

@section('subtitle', 'Map source attributes to Kasta attributes inside a feed profile and source category scope.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="source_category_id">Source category</label>
                <select id="source_category_id" name="source_category_id">
                    <option value="">All</option>
                    @foreach($sourceCategories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['source_category_id'] ?? '') === (string) $category->id)>{{ $category->full_path ?: $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="kasta_category_id">Kasta category</label>
                <input id="kasta_category_id" name="kasta_category_id" value="{{ $filters['kasta_category_id'] ?? '' }}" placeholder="auto-detected when blank">
            </div>
            <div class="field" style="align-self: end;"><button class="button secondary" type="submit">Apply scope</button></div>
        </form>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>{{ $selectedMapping ? 'Edit mapping' : 'Create mapping' }}</h2>
            <form method="POST" action="{{ $selectedMapping ? route('admin.feed-profiles.attribute-mappings.update', [$feedProfile, $selectedMapping]) : route('admin.feed-profiles.attribute-mappings.store', $feedProfile) }}">
                @csrf
                @if($selectedMapping)
                    @method('PUT')
                @endif
                <div class="form-grid">
                    <div class="field">
                        <label for="form_source_category_id">Source category</label>
                        <select id="form_source_category_id" name="source_category_id">
                            <option value="">Global</option>
                            @foreach($sourceCategories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('source_category_id', $selectedMapping?->source_category_id ?? ($filters['source_category_id'] ?? '')) === (string) $category->id)>{{ $category->full_path ?: $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="source_attribute_id">Source attribute</label>
                        <select id="source_attribute_id" name="source_attribute_id" required>
                            @foreach($sourceAttributes as $attribute)
                                <option value="{{ $attribute->id }}" @selected((string) old('source_attribute_id', $selectedMapping?->source_attribute_id) === (string) $attribute->id)>{{ $attribute->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="form_kasta_category_id">Kasta category</label>
                        <input id="form_kasta_category_id" name="kasta_category_id" value="{{ old('kasta_category_id', $selectedMapping?->kasta_category_id ?? $targetCategoryId) }}">
                    </div>
                    <div class="field">
                        <label for="kasta_attribute_id">Kasta attribute</label>
                        <select id="kasta_attribute_id" name="kasta_attribute_id" required>
                            @foreach($kastaAttributes as $attribute)
                                <option value="{{ $attribute->id }}" @selected((string) old('kasta_attribute_id', $selectedMapping?->kasta_attribute_id) === (string) $attribute->id)>{{ $attribute->name }} ({{ $attribute->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="default_value">Default value</label>
                        <input id="default_value" name="default_value" value="{{ old('default_value', $selectedMapping?->default_value) }}">
                    </div>
                    <div class="field full">
                        <div class="checks">
                            <label class="check"><input type="checkbox" name="is_required" value="1" @checked(old('is_required', $selectedMapping?->is_required))> Required</label>
                            <label class="check"><input type="checkbox" name="use_variant_value" value="1" @checked(old('use_variant_value', $selectedMapping?->use_variant_value ?? true))> Use variant value</label>
                        </div>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button" type="submit">{{ $selectedMapping ? 'Save mapping' : 'Create mapping' }}</button>
                    @if($selectedMapping)
                        <a class="button secondary" href="{{ route('admin.feed-profiles.attribute-mappings.index', ['feed_profile' => $feedProfile, 'source_category_id' => $filters['source_category_id'] ?? null, 'kasta_category_id' => $filters['kasta_category_id'] ?? null]) }}">Cancel edit</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Required Attributes</h2>
            @if($requiredAttributes->isEmpty())
                <p class="muted">Select or auto-map a source category to inspect required Kasta attributes.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Attribute</th><th>Code</th><th>Mapped</th></tr></thead>
                        <tbody>
                        @foreach($requiredAttributes as $attribute)
                            <tr>
                                <td>{{ $attribute->name }}</td>
                                <td>{{ $attribute->code }}</td>
                                <td>{{ $unmappedRequiredAttributes->contains('id', $attribute->id) ? 'No' : 'Yes' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($unmappedRequiredAttributes->isNotEmpty())
                    <h3>Unmapped required attributes</h3>
                    <ul>
                        @foreach($unmappedRequiredAttributes as $attribute)
                            <li>{{ $attribute->name }} ({{ $attribute->code }})</li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>
    </div>

    @if(! empty($suggestions))
        <section class="panel">
            <h2>Exact Match Suggestions</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.attribute-mappings.suggestions', $feedProfile) }}">
                @csrf
                <input type="hidden" name="source_category_id" value="{{ $filters['source_category_id'] ?? '' }}">
                <div class="toolbar">
                    <button class="button secondary" type="submit">Apply selected suggestions</button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th></th><th>Source attribute</th><th>Kasta attribute</th></tr></thead>
                        <tbody>
                        @foreach($suggestions as $suggestion)
                            <tr>
                                <td><input type="checkbox" name="source_attribute_ids[]" value="{{ $suggestion['source_attribute']->id }}" checked></td>
                                <td>{{ $suggestion['source_attribute']->name }}</td>
                                <td>{{ $suggestion['kasta_attribute']->name }} ({{ $suggestion['kasta_attribute']->code }})</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        </section>
    @endif

    <section class="panel">
        <h2>Current Mappings</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Source attribute</th><th>Kasta attribute</th><th>Strategy</th><th>Required</th><th>Variant value</th><th></th></tr></thead>
                <tbody>
                @forelse($mappings as $mapping)
                    <tr>
                        <td>{{ $mapping->sourceAttribute?->name ?: 'n/a' }}</td>
                        <td>{{ $mapping->kastaAttribute?->name ?: 'n/a' }}</td>
                        <td>{{ $mapping->mapping_strategy }}</td>
                        <td>{{ $mapping->is_required ? 'Yes' : 'No' }}</td>
                        <td>{{ $mapping->use_variant_value ? 'Yes' : 'No' }}</td>
                        <td>
                            <div class="toolbar">
                                <a class="button link" href="{{ route('admin.feed-profiles.attribute-mappings.index', ['feed_profile' => $feedProfile, 'edit' => $mapping->id, 'source_category_id' => $filters['source_category_id'] ?? null, 'kasta_category_id' => $filters['kasta_category_id'] ?? null]) }}">Edit</a>
                                <form method="POST" action="{{ route('admin.feed-profiles.attribute-mappings.destroy', [$feedProfile, $mapping]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="button danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No attribute mappings yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('components.admin.paginator', ['paginator' => $mappings])
    </section>
@endsection
