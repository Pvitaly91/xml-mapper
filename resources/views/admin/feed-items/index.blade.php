@extends('layouts.admin', ['title' => 'Feed Items'])

@section('subtitle', 'Inspect validation state, manually include/exclude items and trigger revalidation.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}">
                @csrf
                <button class="button" type="submit">Rebuild feed</button>
            </form>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Any</option>
                    <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Pending</option>
                    <option value="ready" @selected(($filters['status'] ?? '') === 'ready')>Ready</option>
                    <option value="invalid" @selected(($filters['status'] ?? '') === 'invalid')>Invalid</option>
                    <option value="excluded" @selected(($filters['status'] ?? '') === 'excluded')>Excluded</option>
                </select>
            </div>
            <div class="field">
                <label for="enabled">Enabled</label>
                <select id="enabled" name="enabled">
                    <option value="">Any</option>
                    <option value="1" @selected(($filters['enabled'] ?? '') === '1')>Enabled</option>
                    <option value="0" @selected(($filters['enabled'] ?? '') === '0')>Disabled</option>
                </select>
            </div>
            <div class="field">
                <label for="source_category_id">Source category</label>
                <select id="source_category_id" name="source_category_id">
                    <option value="">Any</option>
                    @foreach($sourceCategories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['source_category_id'] ?? '') === (string) $category->id)>{{ $category->full_path ?: $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="mapped_category_id">Mapped category</label>
                <select id="mapped_category_id" name="mapped_category_id">
                    <option value="">Any</option>
                    @foreach($mappedCategories as $mapping)
                        @if($mapping->kastaCategory)
                            <option value="{{ $mapping->kastaCategory->id }}" @selected((string) ($filters['mapped_category_id'] ?? '') === (string) $mapping->kastaCategory->id)>{{ $mapping->kastaCategory->full_path ?: $mapping->kastaCategory->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="field"><label for="vendor">Vendor</label><input id="vendor" name="vendor" value="{{ $filters['vendor'] ?? '' }}"></div>
            <div class="field"><label for="article">Article</label><input id="article" name="article" value="{{ $filters['article'] ?? '' }}"></div>
            <div class="field">
                <label for="validation_code">Validation code</label>
                <select id="validation_code" name="validation_code">
                    <option value="">Any</option>
                    @foreach($validationCodes as $code)
                        <option value="{{ $code }}" @selected(($filters['validation_code'] ?? '') === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label for="search">Search</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="product name, offer id, article"></div>
            <div class="field" style="align-self: end;"><button class="button secondary" type="submit">Apply filters</button></div>
        </form>
    </section>

    <section class="panel">
        <form method="POST" action="{{ route('admin.feed-profiles.feed-items.bulk', $feedProfile) }}">
            @csrf
            <div class="toolbar">
                <select name="operation">
                    <option value="enable">Enable</option>
                    <option value="disable">Disable</option>
                    <option value="include">Include</option>
                    <option value="exclude">Exclude</option>
                    <option value="revalidate">Revalidate selected</option>
                </select>
                <input name="reason" placeholder="Optional reason">
                <button class="button" type="submit">Apply bulk action</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr><th></th><th>Status</th><th>Product</th><th>Variant</th><th>Categories</th><th>Validation</th><th></th></tr></thead>
                    <tbody>
                    @forelse($items as $item)
                        @php($sourceCategory = $item->sourceProduct?->sourceCategory)
                        @php($mapping = $mappingMap->get($sourceCategory?->id))
                        <tr>
                            <td><input type="checkbox" name="feed_item_ids[]" value="{{ $item->id }}"></td>
                            <td>
                                <span class="badge {{ $item->status === 'ready' ? 'ok' : ($item->status === 'invalid' ? 'err' : 'warn') }}">{{ $item->status }}</span><br>
                                <span class="muted">{{ $item->is_enabled ? 'enabled' : 'disabled' }}</span>
                            </td>
                            <td>
                                <strong>{{ $item->sourceProduct?->name ?: 'n/a' }}</strong><br>
                                <span class="muted">{{ $item->sourceProduct?->vendor ?: 'n/a' }} / {{ $item->sourceProduct?->article ?: 'n/a' }}</span>
                            </td>
                            <td>
                                <strong>{{ $item->sourceVariant?->stable_offer_id ?: 'n/a' }}</strong><br>
                                <span class="muted">{{ $item->sourceVariant?->title ?: 'n/a' }}</span>
                            </td>
                            <td>
                                <div><strong>Source:</strong> {{ $sourceCategory?->full_path ?: $sourceCategory?->name ?: 'n/a' }}</div>
                                <div><strong>Mapped:</strong> {{ $mapping?->kastaCategory?->full_path ?: $mapping?->kastaCategory?->name ?: 'n/a' }}</div>
                            </td>
                            <td>
                                @if($item->activeValidationErrors->isNotEmpty())
                                    @foreach($item->activeValidationErrors as $error)
                                        <div class="badge err" style="margin-bottom: 4px;">{{ $error->code }}</div>
                                    @endforeach
                                @else
                                    <span class="muted">No active errors</span>
                                @endif
                            </td>
                            <td><a class="button link" href="{{ route('admin.feed-profiles.feed-items.show', [$feedProfile, $item]) }}">Details</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">No feed items found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </form>

        @include('components.admin.paginator', ['paginator' => $items])
    </section>
@endsection
