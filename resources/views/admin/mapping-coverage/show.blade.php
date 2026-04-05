@extends('layouts.admin', ['title' => 'Mapping Coverage Center'])

@section('subtitle', 'Deterministic mapping automation, backlog prioritization, reusable templates, and safe bulk apply for this feed profile.')

@section('safety_banner')
    Manual mappings and item-level exceptions remain authoritative. Auto-apply batches use deterministic rules, keep history, and can be rolled back when the batch created or updated mappings.
@endsection

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.workbench.index', $feedProfile) }}">Unresolved workbench</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.mapping-coverage.templates.export', $feedProfile) }}">Export template JSON</a>
        </div>

        <form method="GET" class="filters">
            <div class="field">
                <label for="problem">Workbench problem</label>
                <select id="problem" name="problem">
                    <option value="">Any</option>
                    @foreach($coverage['workbench']['problems'] as $problem)
                        <option value="{{ $problem['key'] }}" @selected(($filters['problem'] ?? '') === $problem['key'])>{{ $problem['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="search">Search</label>
                <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="category, attribute, item">
            </div>
            <div class="field" style="align-self: end;">
                <button class="button secondary" type="submit">Apply filters</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Coverage Summary</h2>
        <div class="stats">
            <div class="stat"><span class="muted">Category coverage</span><strong>{{ $coverage['summary']['category_coverage_pct'] }}%</strong></div>
            <div class="stat"><span class="muted">Attribute mappings</span><strong>{{ $coverage['summary']['attribute_mapping_count'] }}</strong></div>
            <div class="stat"><span class="muted">Value mappings</span><strong>{{ $coverage['summary']['value_mapping_count'] }}</strong></div>
            <div class="stat"><span class="muted">Unresolved mapping items</span><strong>{{ $coverage['summary']['unresolved_mapping_items'] }}</strong></div>
            <div class="stat"><span class="muted">Stored templates</span><strong>{{ $coverage['template_summary']['stored_templates'] }}</strong></div>
            <div class="stat"><span class="muted">Active rules</span><strong>{{ $coverage['template_summary']['active_rules'] }}</strong></div>
        </div>
        <div class="stats" style="margin-top: 14px;">
            <div class="stat"><span class="muted">Ready gain: categories</span><strong>{{ $coverage['estimated_ready_gain']['category'] }}</strong></div>
            <div class="stat"><span class="muted">Ready gain: attributes</span><strong>{{ $coverage['estimated_ready_gain']['attribute'] }}</strong></div>
            <div class="stat"><span class="muted">Ready gain: values</span><strong>{{ $coverage['estimated_ready_gain']['value'] }}</strong></div>
            <div class="stat"><span class="muted">Manual / auto category split</span><strong>{{ $coverage['summary']['manual_split']['category_manual'] }} / {{ $coverage['summary']['auto_split']['category_auto'] }}</strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Auto-Apply Suggestions</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.mapping-coverage.apply', $feedProfile) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="type">Suggestion type</label>
                        <select id="type" name="type">
                            <option value="category">Category</option>
                            <option value="attribute">Attribute</option>
                            <option value="value">Value</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="threshold">Confidence threshold</label>
                        <input id="threshold" name="threshold" value="0.90">
                    </div>
                    <div class="field">
                        <label for="strategy">Apply strategy</label>
                        <select id="strategy" name="strategy">
                            <option value="safe">safe</option>
                            <option value="overwrite_existing">overwrite_existing</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="reason">Reason</label>
                        <input id="reason" name="reason" placeholder="Why this batch should run">
                    </div>
                    <div class="field full">
                        <label class="check"><input type="checkbox" name="dry_run" value="1"> Dry-run only</label>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button" type="submit">Plan / run batch</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Template Library</h2>
            <form method="POST" action="{{ route('admin.feed-profiles.mapping-coverage.templates.store', $feedProfile) }}">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="template_name">Template name</label>
                        <input id="template_name" name="name" placeholder="Spring apparel baseline">
                    </div>
                    <div class="field">
                        <label for="template_scope">Scope</label>
                        <select id="template_scope" name="scope">
                            <option value="feed_profile">feed_profile</option>
                            <option value="shop">shop</option>
                            <option value="global">global</option>
                        </select>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button secondary" type="submit">Store current template</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.feed-profiles.mapping-coverage.templates.apply', $feedProfile) }}" style="margin-top: 18px;">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="mapping_template_id">Stored template</label>
                        <select id="mapping_template_id" name="mapping_template_id">
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->scope }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="collision_strategy">Collision strategy</label>
                        <select id="collision_strategy" name="collision_strategy">
                            <option value="skip_existing">skip_existing</option>
                            <option value="merge_if_safe">merge_if_safe</option>
                            <option value="overwrite_existing">overwrite_existing</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label class="check"><input type="checkbox" name="dry_run" value="1"> Preview only</label>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px;">
                    <button class="button secondary" type="submit">Apply / preview template</button>
                </div>
            </form>
        </section>
    </div>

    <section class="panel">
        <h2>Prioritized Backlog</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Priority bucket</th><th>Subject</th><th>Impact</th><th>Ready gain</th><th>Safe bulk action</th></tr></thead>
                <tbody>
                @forelse($coverage['backlog'] as $item)
                    <tr>
                        <td>{{ $item['bucket'] }}</td>
                        <td>{{ $item['subject'] }}</td>
                        <td>{{ $item['impact_count'] }}</td>
                        <td>{{ $item['readiness_gain'] }}</td>
                        <td>{{ $item['safe_bulk_action'] ? 'Yes' : 'Review first' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No prioritized backlog items.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Category Suggestions</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Source</th><th>Suggested target</th><th>Confidence</th><th>Why suggested</th><th>Unlocks</th></tr></thead>
                    <tbody>
                    @forelse($coverage['suggestions']['category'] as $suggestion)
                        <tr>
                            <td>{{ $suggestion['source']['label'] }}</td>
                            <td>{{ $suggestion['suggested_target']['label'] }}</td>
                            <td>{{ $suggestion['confidence'] }}</td>
                            <td>{{ $suggestion['explanation'] }}</td>
                            <td>{{ $suggestion['unlock_estimate'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No category suggestions.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Attribute Suggestions</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Source</th><th>Suggested target</th><th>Confidence</th><th>Why suggested</th><th>Unlocks</th></tr></thead>
                    <tbody>
                    @forelse($coverage['suggestions']['attribute'] as $suggestion)
                        <tr>
                            <td>{{ $suggestion['source']['label'] }}<div class="muted">{{ $suggestion['source_category_label'] }}</div></td>
                            <td>{{ $suggestion['suggested_target']['label'] }}</td>
                            <td>{{ $suggestion['confidence'] }}</td>
                            <td>{{ $suggestion['explanation'] }}</td>
                            <td>{{ $suggestion['unlock_estimate'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No attribute suggestions.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="panel">
        <h2>Value Suggestions</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Source</th><th>Suggested target</th><th>Confidence</th><th>Why suggested</th><th>Auto-apply safe</th></tr></thead>
                <tbody>
                @forelse($coverage['suggestions']['value'] as $suggestion)
                    <tr>
                        <td>{{ $suggestion['source']['label'] }}<div class="muted">{{ $suggestion['source_category_label'] }}</div></td>
                        <td>{{ $suggestion['suggested_target']['label'] }}</td>
                        <td>{{ $suggestion['confidence'] }}</td>
                        <td>{{ $suggestion['explanation'] }}</td>
                        <td>{{ $suggestion['safe_for_auto_apply'] ? 'Yes' : 'Review' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No value suggestions.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Feedback-Driven Recommendations</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Type</th><th>Subject</th><th>Impact</th><th>Rationale</th></tr></thead>
                    <tbody>
                    @forelse($coverage['feedback_recommendations'] as $recommendation)
                        <tr>
                            <td>{{ $recommendation['recommendation_type'] }}</td>
                            <td>{{ $recommendation['subject'] }}</td>
                            <td>{{ $recommendation['impact_count'] }}</td>
                            <td>{{ $recommendation['rationale'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No repeated feedback patterns detected yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Batch History</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Batch</th><th>Type</th><th>Status</th><th>Risk</th><th>Summary</th><th></th></tr></thead>
                    <tbody>
                    @forelse($coverage['latest_batches'] as $batch)
                        <tr>
                            <td>#{{ $batch->id }}</td>
                            <td>{{ $batch->mapping_type }}</td>
                            <td>{{ $batch->status }}</td>
                            <td>{{ $batch->risk_level }}</td>
                            <td>{{ ($batch->summary['created'] ?? 0) }} create / {{ ($batch->summary['updated'] ?? 0) }} update / {{ ($batch->summary['conflicted'] ?? 0) }} conflict</td>
                            <td>
                                @if($batch->status === 'applied')
                                    <form method="POST" action="{{ route('admin.feed-profiles.mapping-coverage.batches.rollback', [$feedProfile, $batch]) }}">
                                        @csrf
                                        <input name="reason" placeholder="Rollback reason">
                                        <button class="button warning" type="submit">Rollback</button>
                                    </form>
                                @elseif($batch->approvalRequest)
                                    <a class="button link" href="{{ route('admin.access.approvals.show', $batch->approvalRequest) }}">Open approval</a>
                                @else
                                    <span class="muted">n/a</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">No mapping batches recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
