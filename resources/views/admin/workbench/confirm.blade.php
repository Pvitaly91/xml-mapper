@extends('layouts.admin', ['title' => 'Confirm Bulk Action'])

@section('subtitle', 'Review risky workbench actions before they change exclusion state or rebuild the candidate.')

@section('content')
    <section class="panel">
        <h2>{{ $preview['label'] }}</h2>
        <div class="detail-list">
            <div class="detail-row"><strong>Items selected</strong><div>{{ $preview['items_count'] }}</div></div>
            <div class="detail-row"><strong>Reason</strong><div>{{ $preview['reason'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Risk level</strong><div>{{ $preview['risk_level'] }}</div></div>
        </div>

        <form method="POST" action="{{ route('admin.feed-profiles.workbench.bulk-execute', $feedProfile) }}" style="margin-top: 18px;">
            @csrf
            <input type="hidden" name="operation" value="{{ $preview['operation'] }}">
            <input type="hidden" name="reason" value="{{ $preview['reason'] }}">
            @foreach($preview['feed_item_ids'] as $feedItemId)
                <input type="hidden" name="feed_item_ids[]" value="{{ $feedItemId }}">
            @endforeach
            <label class="check" style="margin-bottom: 16px;">
                <input type="checkbox" name="confirm" value="1" required>
                I understand this action changes the current shop workflow.
            </label>
            <div class="toolbar">
                <button class="button danger" type="submit">Confirm action</button>
                <a class="button secondary" href="{{ route('admin.feed-profiles.workbench.index', $feedProfile) }}">Cancel</a>
            </div>
        </form>
    </section>
@endsection
