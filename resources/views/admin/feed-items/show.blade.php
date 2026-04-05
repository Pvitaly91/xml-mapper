@extends('layouts.admin', ['title' => 'Feed Item #'.$feedItem->id])

@section('subtitle', 'Source snapshots, export diagnostics, XML preview, active validation errors, and manual override controls.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.feed-items.index', $feedProfile) }}">Back to list</a>
            <form method="POST" action="{{ route('admin.feed-profiles.feed-items.bulk', $feedProfile) }}">
                @csrf
                <input type="hidden" name="feed_item_ids[]" value="{{ $feedItem->id }}">
                <input type="hidden" name="operation" value="revalidate">
                <button class="button" type="submit">Revalidate item</button>
            </form>
            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}">
                @csrf
                <button class="button secondary" type="submit">Rebuild feed</button>
            </form>
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Status</strong><div>{{ $feedItem->status }}</div></div>
            <div class="detail-row"><strong>Enabled</strong><div>{{ $feedItem->is_enabled ? 'Yes' : 'No' }}</div></div>
            <div class="detail-row"><strong>Manual override</strong><div>{{ $feedItem->is_manual_override ? 'Yes' : 'No' }}</div></div>
            <div class="detail-row"><strong>Excluded reason</strong><div>{{ $feedItem->excluded_reason ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Mapped category</strong><div>{{ $mappedCategory['full_path'] ?? $mappedCategory['name'] ?? 'n/a' }}</div></div>
            <div class="detail-row"><strong>Last exported at</strong><div>{{ optional($feedItem->last_exported_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Manual Override</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.feed-items.override', [$feedProfile, $feedItem]) }}">
                @csrf
                @method('PUT')
                <div class="checks">
                    <label class="check"><input type="radio" name="is_enabled" value="1" {{ $feedItem->is_enabled ? 'checked' : '' }}> Include</label>
                    <label class="check"><input type="radio" name="is_enabled" value="0" {{ ! $feedItem->is_enabled ? 'checked' : '' }}> Exclude</label>
                </div>
                <div class="field" style="margin-top: 12px;">
                    <label for="excluded_reason">Excluded reason</label>
                    <input id="excluded_reason" name="excluded_reason" value="{{ old('excluded_reason', $feedItem->excluded_reason) }}">
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button" type="submit">Save override</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Operator Summary</h2>
            @if($diagnostics)
                <p>{{ $diagnostics['diagnostics_summary']['operator_summary']['headline'] ?? 'n/a' }}</p>
                @if(! empty($exceptionRows))
                    <p><strong>Active item exceptions:</strong></p>
                    <ul>
                        @foreach($exceptionRows as $exception)
                            <li>{{ $exception['type'] }}: {{ $exception['target_label'] ?? $exception['target_value'] }} ({{ $exception['reason'] }})</li>
                        @endforeach
                    </ul>
                @endif
                @if(! empty($diagnostics['diagnostics_summary']['operator_summary']['missing_required_attributes']))
                    <ul>
                        @foreach($diagnostics['diagnostics_summary']['operator_summary']['missing_required_attributes'] as $issue)
                            <li>{{ $issue['attribute_name'] }}: {{ $issue['message'] }}</li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="muted">Diagnostics are not available.</p>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Content Override</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.feed-items.content-override', [$feedProfile, $feedItem]) }}">
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="field">
                        <label for="content_title">Title</label>
                        <input id="content_title" name="title" value="{{ old('title', data_get($diagnostics, 'enrichment.content.title')) }}">
                    </div>
                    <div class="field">
                        <label for="content_vendor">Vendor / brand</label>
                        <input id="content_vendor" name="vendor" value="{{ old('vendor', data_get($diagnostics, 'enrichment.content.vendor')) }}">
                    </div>
                    <div class="field">
                        <label for="content_article">Article</label>
                        <input id="content_article" name="article" value="{{ old('article', data_get($diagnostics, 'enrichment.content.article')) }}">
                    </div>
                    <div class="field">
                        <label for="content_color">Color</label>
                        <input id="content_color" name="color" value="{{ old('color', data_get($diagnostics, 'enrichment.content.color')) }}">
                    </div>
                    <div class="field">
                        <label for="content_size">Size</label>
                        <input id="content_size" name="size" value="{{ old('size', data_get($diagnostics, 'enrichment.content.size')) }}">
                    </div>
                    <div class="field">
                        <label for="content_size_grid">Size grid code</label>
                        <input id="content_size_grid" name="size_grid_code" value="{{ old('size_grid_code', data_get($diagnostics, 'normalized_export_snapshot.size_grid_code')) }}">
                    </div>
                    <div class="field full">
                        <label for="content_description">Description</label>
                        <textarea id="content_description" name="description">{{ old('description', data_get($diagnostics, 'enrichment.content.description')) }}</textarea>
                    </div>
                    <div class="field full">
                        <label for="content_images">Images, one URL per line</label>
                        <textarea id="content_images" name="images">{{ old('images', implode(PHP_EOL, data_get($diagnostics, 'enrichment.content.images', []))) }}</textarea>
                    </div>
                    <div class="field full">
                        <label for="content_reason">Reason</label>
                        <input id="content_reason" name="reason" value="{{ old('reason') }}" placeholder="Why this item needs persisted content override">
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button secondary" type="submit">Save content override</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Enrichment Preview</h2>
            @if($diagnostics)
                <div class="detail-list">
                    <div class="detail-row"><strong>Contract profile</strong><div>{{ data_get($diagnostics, 'contract.profile_key', 'default') }}</div></div>
                    <div class="detail-row"><strong>Family key</strong><div>{{ data_get($diagnostics, 'family_context.family_key') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Size grid</strong><div>{{ data_get($diagnostics, 'family_context.size_grid_code') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Suggested changes</strong><div>{{ collect(data_get($diagnostics, 'enrichment.diff', []))->filter(fn ($row) => $row['changed'] ?? false)->keys()->implode(', ') ?: 'none' }}</div></div>
                </div>

                @if((data_get($diagnostics, 'enrichment.warnings', [])) !== [])
                    <h3 style="margin-top: 18px;">Warnings</h3>
                    <ul>
                        @foreach(data_get($diagnostics, 'enrichment.warnings', []) as $warning)
                            <li>{{ $warning['code'] }}: {{ $warning['message'] }}</li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="muted">No enrichment preview is available.</p>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Item-Level Category Exception</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.feed-items.exceptions.category', [$feedProfile, $feedItem]) }}">
                @csrf
                <div class="field">
                    <label for="kasta_category_id">Override category</label>
                    <select id="kasta_category_id" name="kasta_category_id">
                        @foreach($kastaCategories as $category)
                            <option value="{{ $category->id }}" @selected(($mappedCategory['id'] ?? null) === $category->id)>{{ $category->full_path ?: $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin-top: 12px;">
                    <label for="category_exception_reason">Reason</label>
                    <input id="category_exception_reason" name="reason" value="{{ old('reason') }}" placeholder="Why this one item needs a different category">
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button secondary" type="submit">Save category exception</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Item-Level Attribute Exception</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.feed-items.exceptions.attribute', [$feedProfile, $feedItem]) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="attribute_code">Kasta attribute code</label>
                        <input id="attribute_code" name="attribute_code" value="{{ old('attribute_code') }}" placeholder="color">
                    </div>
                    <div class="field">
                        <label for="target_value">Forced value</label>
                        <input id="target_value" name="target_value" value="{{ old('target_value') }}" placeholder="Black">
                    </div>
                    <div class="field full">
                        <label for="attribute_exception_reason">Reason</label>
                        <input id="attribute_exception_reason" name="reason" value="{{ old('reason') }}" placeholder="Why this one item needs a value override">
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button secondary" type="submit">Save attribute exception</button>
                </div>
            </form>
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Validation Errors</h2>
            @if($feedItem->activeValidationErrors->isEmpty())
                <p class="muted">No active validation errors.</p>
            @else
                <ul>
                    @foreach($feedItem->activeValidationErrors as $error)
                        <li><strong>{{ $error->code }}</strong>: {{ $error->message }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel">
            <h2>Required Attribute Diagnostics</h2>
            @if($diagnostics && ! empty($diagnostics['required_attribute_diagnostics']))
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Attribute</th><th>Status</th><th>Failure type</th><th>Source value</th><th>Mapped value</th></tr></thead>
                        <tbody>
                        @foreach($diagnostics['required_attribute_diagnostics'] as $required)
                            <tr>
                                <td>{{ $required['attribute_name'] }}</td>
                                <td>{{ $required['status'] }}</td>
                                <td>{{ $required['failure_type'] ?: 'ok' }}</td>
                                <td>{{ $required['source_value'] ?: 'n/a' }}</td>
                                <td>{{ $required['mapped_value'] ?: 'n/a' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="muted">No required attribute diagnostics.</p>
            @endif
        </section>
    </div>

    <section class="panel">
        <h2>Mapped Attributes / Values</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Source attribute</th><th>Source value</th><th>Kasta attribute</th><th>Mapped value</th><th>Resolution</th></tr></thead>
                <tbody>
                @forelse($attributeRows as $row)
                    <tr>
                        <td>{{ $row['source_attribute'] }}</td>
                        <td>{{ $row['source_value'] ?: 'n/a' }}</td>
                        <td>{{ $row['kasta_attribute'] }}</td>
                        <td>{{ $row['target_value'] ?: 'n/a' }}</td>
                        <td>{{ $row['resolution'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No resolved attribute mappings.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Normalized Export Snapshot</h2>
            <pre>{{ json_encode($diagnostics['normalized_export_snapshot'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
        <section class="panel">
            <h2>XML Preview</h2>
            @if($xmlPreview)
                <pre>{{ $xmlPreview }}</pre>
            @else
                <p class="muted">Preview is unavailable until category mapping and export snapshot are complete.</p>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Resolved Feed Params</h2>
            <pre>{{ json_encode($resolvedAttributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
        <section class="panel">
            <h2>Source Product Snapshot</h2>
            <pre>{{ json_encode([
                'name' => $feedItem->sourceProduct?->name,
                'vendor' => $feedItem->sourceProduct?->vendor,
                'article' => $feedItem->sourceProduct?->article,
                'attributes_snapshot' => $feedItem->sourceProduct?->attributes_snapshot,
                'raw_payload' => $feedItem->sourceProduct?->raw_payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
    </div>

    <section class="panel">
        <h2>Source Variant Snapshot</h2>
        <pre>{{ json_encode([
            'stable_offer_id' => $feedItem->sourceVariant?->stable_offer_id,
            'title' => $feedItem->sourceVariant?->title,
            'price' => $feedItem->sourceVariant?->price,
            'attributes_snapshot' => $feedItem->sourceVariant?->attributes_snapshot,
            'raw_payload' => $feedItem->sourceVariant?->raw_payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>
@endsection
