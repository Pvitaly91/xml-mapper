@extends('layouts.admin', ['title' => $feedProfile->name.' Feedback Import'])

@section('subtitle', 'Manual acceptance/rejection feedback import for first pilot execution. CSV and JSON dry-run are supported.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.feedback-workbench.index', $feedProfile) }}">Rejection workbench</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
        </div>
    </section>

    <section class="panel">
        <form method="POST" action="{{ route('admin.feed-profiles.feedback.preview', $feedProfile) }}" enctype="multipart/form-data">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="format">Format</label>
                    <select id="format" name="format">
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                    </select>
                </div>
                <div class="field">
                    <label for="generation_id">Generation</label>
                    <select id="generation_id" name="generation_id">
                        <option value="">Latest published</option>
                        @foreach($feedProfile->generations()->latest('id')->limit(20)->get() as $generation)
                            <option value="{{ $generation->id }}">#{{ $generation->id }} ({{ $generation->release_status }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field full">
                    <label for="file">Feedback file</label>
                    <input id="file" type="file" name="file" required>
                </div>
            </div>
            <button class="button secondary" type="submit">Dry-run preview</button>
        </form>

        <form method="POST" action="{{ route('admin.feed-profiles.feedback.store', $feedProfile) }}" enctype="multipart/form-data" style="margin-top: 18px;">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="format_store">Format</label>
                    <select id="format_store" name="format">
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                    </select>
                </div>
                <div class="field">
                    <label for="generation_id_store">Generation</label>
                    <select id="generation_id_store" name="generation_id">
                        <option value="">Latest published</option>
                        @foreach($feedProfile->generations()->latest('id')->limit(20)->get() as $generation)
                            <option value="{{ $generation->id }}">#{{ $generation->id }} ({{ $generation->release_status }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field full">
                    <label for="file_store">Feedback file</label>
                    <input id="file_store" type="file" name="file" required>
                </div>
                <div class="field full">
                    <label for="confirmation_store">Confirmation</label>
                    <input id="confirmation_store" name="confirmation" placeholder="Type CONFIRM if high-risk confirmation is enabled">
                </div>
            </div>
            <button class="button" type="submit">Import feedback</button>
        </form>
    </section>

    @if($preview)
        <section class="panel">
            <h2>Dry-Run Summary</h2>
            <div class="stats">
                <div class="stat"><span class="muted">Matched</span><strong>{{ $preview['summary']['matched'] }}</strong></div>
                <div class="stat"><span class="muted">Unmatched</span><strong>{{ $preview['summary']['unmatched'] }}</strong></div>
                <div class="stat"><span class="muted">Accepted</span><strong>{{ $preview['summary']['accepted'] }}</strong></div>
                <div class="stat"><span class="muted">Rejected</span><strong>{{ $preview['summary']['rejected'] }}</strong></div>
                <div class="stat"><span class="muted">Warnings</span><strong>{{ $preview['summary']['warnings'] }}</strong></div>
            </div>

            <div class="table-wrap" style="margin-top: 16px;">
                <table>
                    <thead><tr><th>Status</th><th>Offer</th><th>Article</th><th>Matched</th><th>Reason</th></tr></thead>
                    <tbody>
                    @foreach(array_slice($preview['rows'], 0, 25) as $row)
                        <tr>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['offer_id'] ?: $row['external_item_reference'] ?: 'n/a' }}</td>
                            <td>{{ $row['article'] ?: $row['vendor_code'] ?: 'n/a' }}</td>
                            <td>{{ $row['matched'] ? 'yes' : 'no' }}</td>
                            <td>{{ $row['rejection_reason_code'] ?: $row['rejection_reason_message'] ?: 'n/a' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
