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
