@extends('layouts.admin', ['title' => $feedProfile->name.' Rejection Workbench'])

@section('subtitle', 'Manual remediation queue for imported merchant acceptance and rejection feedback.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.feedback.create', $feedProfile) }}">Import feedback</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
        </div>
    </section>

    <div class="stats">
        <div class="stat"><span class="muted">Rejected</span><strong>{{ $workbench['summary']['rejected'] }}</strong></div>
        <div class="stat"><span class="muted">Warnings</span><strong>{{ $workbench['summary']['warnings'] }}</strong></div>
        <div class="stat"><span class="muted">Unmatched</span><strong>{{ $workbench['summary']['unmatched'] }}</strong></div>
        <div class="stat"><span class="muted">Open</span><strong>{{ $workbench['summary']['open'] }}</strong></div>
        <div class="stat"><span class="muted">In progress</span><strong>{{ $workbench['summary']['in_progress'] }}</strong></div>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <form method="GET" action="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}" class="form-grid">
                <div class="field">
                    <label for="problem">Problem filter</label>
                    <select id="problem" name="problem">
                        <option value="">all</option>
                        <option value="unmatched_feedback" @selected(($workbench['filters']['problem'] ?? '') === 'unmatched_feedback')>unmatched feedback</option>
                        <option value="missing_mapping" @selected(($workbench['filters']['problem'] ?? '') === 'missing_mapping')>missing mapping</option>
                        <option value="content_issues" @selected(($workbench['filters']['problem'] ?? '') === 'content_issues')>content issues</option>
                        <option value="image_issues" @selected(($workbench['filters']['problem'] ?? '') === 'image_issues')>image issues</option>
                        <option value="pricing_issues" @selected(($workbench['filters']['problem'] ?? '') === 'pricing_issues')>pricing issues</option>
                        <option value="size_color_issues" @selected(($workbench['filters']['problem'] ?? '') === 'size_color_issues')>size/color issues</option>
                    </select>
                </div>
                <div class="field">
                    <label for="resolution_status">Resolution status</label>
                    <select id="resolution_status" name="resolution_status">
                        <option value="">all</option>
                        @foreach(['open', 'in_progress', 'fixed', 'wont_fix', 'excluded'] as $status)
                            <option value="{{ $status }}" @selected(($workbench['filters']['resolution_status'] ?? '') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Feedback status</label>
                    <select id="status" name="status">
                        <option value="">all</option>
                        @foreach(['accepted', 'rejected', 'warning', 'unknown'] as $status)
                            <option value="{{ $status }}" @selected(($workbench['filters']['status'] ?? '') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field full">
                    <button class="button secondary" type="submit">Apply filters</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Grouped Reasons</h2>
            @if($workbench['grouped_reasons']->isNotEmpty())
                <ul>
                    @foreach($workbench['grouped_reasons'] as $row)
                        <li>{{ $row['reason_code'] }}: {{ $row['reason_message'] }} ({{ $row['count'] }})</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No imported feedback reasons yet.</p>
            @endif
        </section>
    </div>

    <section class="panel">
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Status</th><th>Reason</th><th>Resolution</th><th>Quick actions</th></tr></thead>
                <tbody>
                @forelse($workbench['records'] as $record)
                    <tr>
                        <td>#{{ $record->id }}</td>
                        <td>{{ $record->status }}</td>
                        <td>{{ $record->rejection_reason_code ?: 'n/a' }}<br><span class="muted">{{ $record->rejection_reason_message ?: 'n/a' }}</span></td>
                        <td>
                            <form method="POST" action="{{ route('admin.feed-profiles.feedback-records.update', [$feedProfile, $record]) }}">
                                @csrf
                                @method('PUT')
                                <select name="resolution_status">
                                    @foreach(['open', 'in_progress', 'fixed', 'wont_fix', 'excluded'] as $status)
                                        <option value="{{ $status }}" @selected($record->resolution_status === $status)>{{ $status }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="resolution_note" value="{{ $record->resolution_note }}" placeholder="Resolution note">
                                <button class="button secondary" type="submit">Save</button>
                            </form>
                        </td>
                        <td>
                            @if($record->feedItem)
                                <a class="button link" href="{{ route('admin.feed-profiles.feed-items.show', [$feedProfile, $record->feedItem]) }}">Diagnostics</a>
                            @endif
                            <a class="button link" href="{{ route('admin.feed-profiles.category-mappings.index', $feedProfile) }}">Category mappings</a>
                            <a class="button link" href="{{ route('admin.feed-profiles.attribute-mappings.index', $feedProfile) }}">Attribute mappings</a>
                            <a class="button link" href="{{ route('admin.feed-profiles.value-mappings.index', $feedProfile) }}">Value mappings</a>
                            <form method="POST" action="{{ route('admin.feed-profiles.build', $feedProfile) }}" style="display: inline-flex;">
                                @csrf
                                <button class="button secondary" type="submit">Rebuild candidate</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No feedback records yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px;">
            {{ $workbench['records']->links() }}
        </div>
    </section>
@endsection
