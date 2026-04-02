@extends('layouts.admin', ['title' => 'Value Mappings'])

@section('subtitle', 'Map source attribute values to Kasta dictionary values and approve exact-match suggestions.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="attribute_mapping_id">Attribute mapping</label>
                <select id="attribute_mapping_id" name="attribute_mapping_id">
                    <option value="">Select mapping</option>
                    @foreach($attributeMappings as $mapping)
                        <option value="{{ $mapping->id }}" @selected((string) request('attribute_mapping_id', $selectedAttributeMapping?->id) === (string) $mapping->id)>
                            {{ $mapping->sourceAttribute?->name }} -> {{ $mapping->kastaAttribute?->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="align-self: end;"><button class="button secondary" type="submit">Apply scope</button></div>
        </form>
    </section>

    @if($selectedAttributeMapping)
        <section class="panel">
            <h2>{{ $selectedValueMapping ? 'Edit value mapping' : 'Create value mapping' }}</h2>
            <form method="POST" action="{{ $selectedValueMapping ? route('admin.feed-profiles.value-mappings.update', [$feedProfile, $selectedAttributeMapping, $selectedValueMapping]) : route('admin.feed-profiles.value-mappings.store', [$feedProfile, $selectedAttributeMapping]) }}">
                @csrf
                @if($selectedValueMapping)
                    @method('PUT')
                @endif
                <div class="form-grid">
                    <div class="field">
                        <label for="source_attribute_value_id">Known source value</label>
                        <select id="source_attribute_value_id" name="source_attribute_value_id">
                            <option value="">None</option>
                            @foreach($sourceValues as $value)
                                <option value="{{ $value->id }}" @selected((string) old('source_attribute_value_id', $selectedValueMapping?->source_attribute_value_id) === (string) $value->id)>{{ $value->raw_value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="source_raw_value">Source raw value</label>
                        <input id="source_raw_value" name="source_raw_value" value="{{ old('source_raw_value', $selectedValueMapping?->source_raw_value) }}" required>
                    </div>
                    <div class="field">
                        <label for="kasta_attribute_value_id">Kasta dictionary value</label>
                        <select id="kasta_attribute_value_id" name="kasta_attribute_value_id">
                            <option value="">Manual target value</option>
                            @foreach($kastaValues as $value)
                                <option value="{{ $value->id }}" @selected((string) old('kasta_attribute_value_id', $selectedValueMapping?->kasta_attribute_value_id) === (string) $value->id)>{{ $value->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="target_value">Manual target value</label>
                        <input id="target_value" name="target_value" value="{{ old('target_value', $selectedValueMapping?->target_value) }}">
                    </div>
                    <div class="field full">
                        <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $selectedValueMapping?->is_active ?? true))> Active mapping</label>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button" type="submit">{{ $selectedValueMapping ? 'Save mapping' : 'Create mapping' }}</button>
                    @if($selectedValueMapping)
                        <a class="button secondary" href="{{ route('admin.feed-profiles.value-mappings.index', ['feed_profile' => $feedProfile, 'attribute_mapping_id' => $selectedAttributeMapping->id]) }}">Cancel edit</a>
                    @endif
                </div>
            </form>
        </section>

        @if(! empty($suggestions))
            <section class="panel">
                <h2>Exact Match Suggestions</h2>
                <form method="POST" action="{{ route('admin.feed-profiles.value-mappings.suggestions', [$feedProfile, $selectedAttributeMapping]) }}">
                    @csrf
                    <div class="toolbar">
                        <button class="button secondary" type="submit">Approve selected suggestions</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th></th><th>Source value</th><th>Kasta value</th></tr></thead>
                            <tbody>
                            @foreach($suggestions as $suggestion)
                                <tr>
                                    <td><input type="checkbox" name="source_attribute_value_ids[]" value="{{ $suggestion['source_attribute_value']->id }}" checked></td>
                                    <td>{{ $suggestion['source_attribute_value']->raw_value }}</td>
                                    <td>{{ $suggestion['kasta_attribute_value']->value }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
            </section>
        @endif
    @endif

    <section class="panel">
        <h2>Current Value Mappings</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Source value</th><th>Normalized</th><th>Target value</th><th>Strategy</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($valueMappings as $mapping)
                    <tr>
                        <td>{{ $mapping->source_raw_value }}</td>
                        <td>{{ $mapping->normalized_source_value ?: 'n/a' }}</td>
                        <td>{{ $mapping->kastaAttributeValue?->value ?: $mapping->target_value ?: 'n/a' }}</td>
                        <td>{{ $mapping->mapping_strategy }}</td>
                        <td>{{ $mapping->is_active ? 'active' : 'inactive' }}</td>
                        <td>
                            @if($selectedAttributeMapping && $mapping->attribute_mapping_id === $selectedAttributeMapping->id)
                                <div class="toolbar">
                                    <a class="button link" href="{{ route('admin.feed-profiles.value-mappings.index', ['feed_profile' => $feedProfile, 'attribute_mapping_id' => $selectedAttributeMapping->id, 'edit' => $mapping->id]) }}">Edit</a>
                                    <form method="POST" action="{{ route('admin.feed-profiles.value-mappings.destroy', [$feedProfile, $selectedAttributeMapping, $mapping]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No value mappings yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('components.admin.paginator', ['paginator' => $valueMappings])
    </section>
@endsection
