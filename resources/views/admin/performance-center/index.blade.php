@extends('layouts.admin', ['title' => 'Performance Center'])

@section('subtitle', 'Scale bootstrap, persisted benchmark history, budget evaluation, and regression comparison for large-catalog readiness.')

@section('safety_banner')
    <strong>Use the same scale fixture profile for repeatable comparisons</strong>
    Benchmark deltas are only meaningful when the dataset size and enabled stages stay comparable across runs.
@endsection

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.dashboard') }}">Dashboard</a>
            @if($selectedFeedProfile)
                <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $selectedFeedProfile) }}">Feed operations</a>
            @endif
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">Latest run</span><strong>{{ $summary['latest']?->run_type ?: 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">Latest budget</span><strong>{{ $summary['latest']?->budget_status ?: 'n/a' }}</strong></div>
            <div class="stat"><span class="muted">7d warnings</span><strong>{{ $summary['warning_count'] }}</strong></div>
            <div class="stat"><span class="muted">7d critical</span><strong>{{ $summary['critical_count'] }}</strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Scale Bootstrap</h2>
            <form method="POST" action="{{ route('admin.performance.bootstrap') }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="bootstrap_products">Products</label>
                        <input id="bootstrap_products" type="number" min="1" max="100000" name="products" value="{{ old('products', 5000) }}">
                    </div>
                    <div class="field">
                        <label for="bootstrap_variants">Variants per product</label>
                        <input id="bootstrap_variants" type="number" min="1" max="20" name="variants_per_product" value="{{ old('variants_per_product', 4) }}">
                    </div>
                    <div class="field">
                        <label for="bootstrap_label">Label</label>
                        <input id="bootstrap_label" name="label" value="{{ old('label') }}" placeholder="optional comparison label">
                    </div>
                    <div class="field">
                        <label for="bootstrap_fresh">Fresh rebuild</label>
                        <select id="bootstrap_fresh" name="fresh">
                            <option value="0">reuse existing scale shop</option>
                            <option value="1">drop and recreate</option>
                        </select>
                    </div>
                </div>
                <button class="button" type="submit" style="margin-top: 12px;">Run scale bootstrap</button>
            </form>
        </section>

        <section class="panel">
            <h2>Benchmark Feed Profile</h2>
            <form method="POST" action="{{ $selectedFeedProfile ? route('admin.feed-profiles.performance.benchmark', $selectedFeedProfile) : '#' }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="compare_feed_profile_id">Feed profile</label>
                        <select id="compare_feed_profile_id" onchange="window.location='?feed_profile_id='+this.value">
                            <option value="">select feed profile</option>
                            @foreach($feedProfiles as $feedProfile)
                                <option value="{{ $feedProfile->id }}" @selected($selectedFeedProfile?->id === $feedProfile->id)>{{ $feedProfile->name }} ({{ $feedProfile->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field full">
                        <label for="benchmark_stages">Stages</label>
                        <input id="benchmark_stages" name="stages" value="{{ old('stages', 'sync,normalize,build,reconciliation,report_generation') }}" placeholder="sync,normalize,build,publish,smoke,reconciliation,report_generation,feedback_import">
                    </div>
                    <div class="field">
                        <label for="benchmark_label">Label</label>
                        <input id="benchmark_label" name="label" value="{{ old('label') }}">
                    </div>
                </div>
                <button class="button secondary" type="submit" style="margin-top: 12px;" @disabled(!$selectedFeedProfile)>Run benchmark</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin: 0;">Recent Run Comparison</h2>
            <span class="badge {{ ($summary['compare']['overall']['status'] ?? 'within_budget') === 'critical' ? 'err' : (($summary['compare']['overall']['status'] ?? 'within_budget') === 'warning' ? 'warn' : 'ok') }}">
                {{ $summary['compare']['overall']['status'] ?? 'within_budget' }}
            </span>
        </div>
        <p class="muted">{{ $summary['compare']['overall']['message'] ?? 'No previous run available.' }}</p>
        @if(($summary['compare']['stages'] ?? []) !== [])
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Stage</th><th>Status</th><th>Delta</th><th>Message</th></tr></thead>
                    <tbody>
                    @foreach($summary['compare']['stages'] as $stage => $comparison)
                        <tr>
                            <td>{{ $stage }}</td>
                            <td>{{ $comparison['status'] }}</td>
                            <td>{{ $comparison['delta_pct'] !== null ? $comparison['delta_pct'].'%' : 'n/a' }}</td>
                            <td>{{ $comparison['message'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel">
        <h2>Runs</h2>
        <form method="GET" class="filters">
            <div class="field">
                <label for="filter_run_type">Run type</label>
                <select id="filter_run_type" name="run_type">
                    <option value="">all</option>
                    @foreach(\App\Models\PerformanceRun::runTypes() as $runType)
                        <option value="{{ $runType }}" @selected(request('run_type') === $runType)>{{ $runType }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_status">Status</label>
                <select id="filter_status" name="status">
                    <option value="">all</option>
                    @foreach(['running','succeeded','warning','failed'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_budget_status">Budget</label>
                <select id="filter_budget_status" name="budget_status">
                    <option value="">all</option>
                    @foreach(['within_budget','warning','critical'] as $status)
                        <option value="{{ $status }}" @selected(request('budget_status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_profile">Feed profile</label>
                <select id="filter_profile" name="feed_profile_id">
                    <option value="">all</option>
                    @foreach($feedProfiles as $feedProfile)
                        <option value="{{ $feedProfile->id }}" @selected((string) request('feed_profile_id') === (string) $feedProfile->id)>{{ $feedProfile->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="align-self: end;">
                <button class="button secondary" type="submit">Filter</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Type</th><th>Scope</th><th>Dataset</th><th>Duration</th><th>Budget</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($runs as $run)
                    <tr>
                        <td>{{ optional($run->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>
                            <strong>{{ $run->run_type }}</strong><br>
                            <span class="muted">{{ $run->status }}</span>
                        </td>
                        <td>
                            {{ $run->feedProfile?->code ?: 'shop-wide' }}<br>
                            <span class="muted">{{ $run->label ?: 'no label' }}</span>
                        </td>
                        <td>
                            {{ number_format($run->dataset_products) }} products<br>
                            <span class="muted">{{ number_format($run->dataset_variants) }} variants</span>
                        </td>
                        <td>
                            {{ $run->duration_ms !== null ? number_format($run->duration_ms).' ms' : 'n/a' }}<br>
                            <span class="muted">peak {{ $run->peak_memory_mb !== null ? number_format($run->peak_memory_mb, 2).' MB' : 'n/a' }}</span>
                        </td>
                        <td>{{ $run->budget_status }}</td>
                        <td>
                            <a class="button link" href="{{ route('admin.performance.show', $run) }}">Details</a>
                            <a class="button link" href="{{ route('admin.performance.report', $run) }}">Download report</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No performance runs recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px;">{{ $runs->links() }}</div>
    </section>
@endsection
