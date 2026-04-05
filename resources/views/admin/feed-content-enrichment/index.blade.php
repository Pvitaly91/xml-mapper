@extends('layouts.admin', ['title' => $feedProfile->name.' Content Enrichment'])

@section('subtitle', 'Preview deterministic content transformations, persist safe overrides in bulk, and inspect item-level content blockers before rebuild.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reconciliation.show', $feedProfile) }}">Functional readiness</a>
            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}">
                @csrf
                <button class="button secondary" type="submit">Rebuild feed</button>
            </form>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="scope">Scope</label>
                <select id="scope" name="scope">
                    <option value="blocked" @selected(($filters['scope'] ?? 'blocked') === 'blocked')>Blocked / excluded</option>
                    <option value="all" @selected(($filters['scope'] ?? '') === 'all')>All current items</option>
                </select>
            </div>
            <div class="field" style="align-self: end;">
                <button class="button secondary" type="submit">Apply filters</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <form method="POST" action="{{ route('admin.feed-profiles.content-enrichment.apply', $feedProfile) }}">
            @csrf
            <div class="toolbar">
                <input name="reason" placeholder="Why persist these deterministic content overrides?" required>
                <button class="button" type="submit">Persist selected enrichment overrides</button>
            </div>

            <div class="table-wrap" style="margin-top: 16px;">
                <table>
                    <thead>
                    <tr>
                        <th></th>
                        <th>Item</th>
                        <th>Status</th>
                        <th>Preview</th>
                        <th>Warnings / blockers</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($items as $item)
                        @php($preview = $previews[$item->id] ?? null)
                        @php($enrichment = $preview['enrichment'] ?? [])
                        @php($diff = $enrichment['diff'] ?? [])
                        <tr>
                            <td>
                                @if(($enrichment['has_suggested_changes'] ?? false) && !($enrichment['manual_override_active'] ?? false))
                                    <input type="checkbox" name="feed_item_ids[]" value="{{ $item->id }}">
                                @endif
                            </td>
                            <td>
                                <strong>{{ $item->sourceProduct?->name ?: 'n/a' }}</strong><br>
                                <span class="muted">{{ $item->sourceVariant?->stable_offer_id ?: 'n/a' }}</span>
                            </td>
                            <td>
                                <span class="badge {{ $item->status === 'ready' ? 'ok' : ($item->status === 'excluded' ? 'warn' : 'err') }}">{{ $item->status }}</span>
                            </td>
                            <td>
                                @if($preview)
                                    <div><strong>Title:</strong> {{ data_get($diff, 'title.final') ?: 'n/a' }}</div>
                                    <div><strong>Description:</strong> {{ \Illuminate\Support\Str::limit((string) data_get($diff, 'description.final'), 120) ?: 'n/a' }}</div>
                                    <div><strong>Images:</strong> {{ count(data_get($diff, 'images.final', [])) }}</div>
                                    <div><strong>Size grid:</strong> {{ data_get($preview, 'family_context.size_grid_code') ?: data_get($preview, 'normalized_export_snapshot.size_grid_code') ?: 'n/a' }}</div>
                                    @if(collect($diff)->contains(fn ($row) => $row['changed'] ?? false))
                                        <div class="muted" style="margin-top: 8px;">
                                            Changed:
                                            {{ collect($diff)->filter(fn ($row) => $row['changed'] ?? false)->keys()->implode(', ') }}
                                        </div>
                                    @endif
                                @else
                                    <span class="muted">No preview</span>
                                @endif
                            </td>
                            <td>
                                @if(($enrichment['warnings'] ?? []) !== [])
                                    @foreach($enrichment['warnings'] as $warning)
                                        <div class="badge warn" style="margin-bottom: 4px;">{{ $warning['code'] }}</div>
                                    @endforeach
                                @endif
                                @if($item->activeValidationErrors->isNotEmpty())
                                    @foreach($item->activeValidationErrors as $error)
                                        <div>{{ $error->code }}: {{ $error->message }}</div>
                                    @endforeach
                                @elseif(($enrichment['warnings'] ?? []) === [])
                                    <span class="muted">No blockers</span>
                                @endif
                            </td>
                            <td>
                                <a class="button link" href="{{ route('admin.feed-profiles.feed-items.show', [$feedProfile, $item]) }}">Item details</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">No feed items match the current enrichment scope.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </form>

        @include('components.admin.paginator', ['paginator' => $items])
    </section>
@endsection
