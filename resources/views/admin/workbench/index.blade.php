@extends('layouts.admin', ['title' => 'Unresolved Mappings Workbench'])

@section('subtitle', 'Resolve concrete blockers by problem type instead of paging through every invalid item.')

@section('content')
    @php($selectedProblem = $workbench['selected_problem'])
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <form method="POST" action="{{ route('admin.feed-profiles.workbench.suggestions', $feedProfile) }}">
                @csrf
                <button class="button" type="submit">Bulk approve suggestions</button>
            </form>
            <form method="POST" action="{{ route('admin.feed-profiles.workbench.value-suggestions', $feedProfile) }}">
                @csrf
                <button class="button secondary" type="submit">Apply exact-match value mappings</button>
            </form>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Release center</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="problem">Problem type</label>
                <select id="problem" name="problem">
                    @foreach($workbench['problems'] as $problem)
                        <option value="{{ $problem['key'] }}" @selected($selectedProblem === $problem['key'])>{{ $problem['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="search">Search</label>
                <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="product, article, offer id">
            </div>
            <div class="field" style="align-self: end;">
                <button class="button secondary" type="submit">Apply filters</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Problem Queue</h2>
        <div class="stats">
            @foreach($workbench['problems'] as $problem)
                <div class="stat">
                    <span class="muted">{{ $problem['label'] }}</span>
                    <strong>{{ $problem['count'] }}</strong>
                    <div style="margin-top: 10px;">
                        <a class="button link" href="{{ route('admin.feed-profiles.workbench.index', ['feed_profile' => $feedProfile, 'problem' => $problem['key']]) }}">Open queue</a>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="panel">
        @php($selectedDefinition = collect($workbench['problems'])->firstWhere('key', $selectedProblem))
        <div class="toolbar">
            <h2 style="margin: 0;">{{ $selectedDefinition['label'] ?? 'Queue' }}</h2>
            @if($selectedDefinition)
                <a class="button link" href="{{ $selectedDefinition['quick_action_url'] }}">Open direct resolution screen</a>
            @endif
        </div>
        <p class="muted">{{ $selectedDefinition['description'] ?? '' }}</p>

        <form method="POST" action="{{ route('admin.feed-profiles.workbench.bulk-confirm', $feedProfile) }}">
            @csrf
            <input type="hidden" name="problem" value="{{ $selectedProblem }}">
            <div class="toolbar">
                <select name="operation">
                    <option value="exclude_items">Mark selected excluded</option>
                    <option value="revalidate_items">Revalidate selected</option>
                    <option value="rebuild_candidate">Rebuild current candidate</option>
                </select>
                <input name="reason" placeholder="Reason for risky action">
                <button class="button" type="submit">Review bulk action</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th></th>
                        <th>Status</th>
                        <th>Product</th>
                        <th>Variant</th>
                        <th>Source category</th>
                        <th>Blocking reasons</th>
                        <th>Quick links</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($workbench['items'] as $item)
                        <tr>
                            <td><input type="checkbox" name="feed_item_ids[]" value="{{ $item->id }}"></td>
                            <td>{{ $item->status }}</td>
                            <td>{{ $item->sourceProduct?->name ?: 'n/a' }}</td>
                            <td>{{ $item->sourceVariant?->stable_offer_id ?: 'n/a' }}</td>
                            <td>{{ $item->sourceProduct?->sourceCategory?->full_path ?: $item->sourceProduct?->sourceCategory?->name ?: 'n/a' }}</td>
                            <td>
                                @foreach($item->activeValidationErrors as $error)
                                    <div class="badge err" style="margin-bottom: 4px;">{{ $error->code }}</div>
                                @endforeach
                                @if($item->activeValidationErrors->isEmpty())
                                    <span class="muted">{{ $item->excluded_reason ?: 'n/a' }}</span>
                                @endif
                            </td>
                            <td>
                                <a class="button link" href="{{ route('admin.feed-profiles.feed-items.show', [$feedProfile, $item]) }}">Item details</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">No items matched this problem queue.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </form>

        @include('components.admin.paginator', ['paginator' => $workbench['items']])
    </section>
@endsection
