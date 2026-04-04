@extends('layouts.admin', ['title' => 'Delivery #'.$delivery->id])

@section('subtitle', 'Detailed outbound notification payload, delivery result, correlation id, and linked incident context.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.notifications.index') }}">Back to Notification Center</a>
            @if($delivery->feedProfile)
                <a class="button secondary" href="{{ route('admin.feed-profiles.hypercare.show', $delivery->feedProfile) }}">Open hypercare</a>
            @endif
            @if($delivery->launch)
                <a class="button secondary" href="{{ route('admin.merchant-launches.show', $delivery->launch) }}">Open launch</a>
            @endif
        </div>

        <div class="stats">
            <div class="stat"><span class="muted">Status</span><strong>{{ $delivery->status }}</strong></div>
            <div class="stat"><span class="muted">Channel</span><strong>{{ $delivery->channel }}</strong></div>
            <div class="stat"><span class="muted">Attempts</span><strong>{{ $delivery->attempts }}</strong></div>
            <div class="stat"><span class="muted">Correlation</span><strong style="font-size: 16px;"><code>{{ $delivery->correlation_id ?: 'n/a' }}</code></strong></div>
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Delivery Summary</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Event type</strong><div>{{ $delivery->event_type }}</div></div>
                <div class="detail-row"><strong>Event family</strong><div>{{ $delivery->event_family ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Severity</strong><div>{{ $delivery->severity }}</div></div>
                <div class="detail-row"><strong>Target</strong><div>{{ $delivery->target_label ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Started</strong><div>{{ optional($delivery->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Delivered</strong><div>{{ optional($delivery->delivered_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Failed</strong><div>{{ optional($delivery->failed_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Next retry</strong><div>{{ optional($delivery->next_retry_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Last error</strong><div>{{ $delivery->last_error ?: 'n/a' }}</div></div>
            </div>
        </section>

        <section class="panel">
            <h2>Linked Entities</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Alert</strong><div>{{ $delivery->alert?->id ? '#'.$delivery->alert->id.' / '.$delivery->alert->title : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Feed profile</strong><div>{{ $delivery->feedProfile?->code ?: 'n/a' }}</div></div>
                <div class="detail-row"><strong>Launch</strong><div>{{ $delivery->launch?->id ? '#'.$delivery->launch->id.' / '.$delivery->launch->state : 'n/a' }}</div></div>
                <div class="detail-row"><strong>Hypercare</strong><div>{{ $delivery->hypercareWindow?->id ? '#'.$delivery->hypercareWindow->id.' / '.$delivery->hypercareWindow->status : 'n/a' }}</div></div>
            </div>
            @if(in_array($delivery->status, ['failed','pending_delivery'], true))
                <form method="POST" action="{{ route('admin.notifications.deliveries.retry', $delivery) }}" style="margin-top: 16px;">
                    @csrf
                    <button class="button secondary" type="submit">Retry delivery</button>
                </form>
            @endif
        </section>
    </div>

    <section class="panel">
        <h2>Rendered Payload</h2>
        <pre>{{ $delivery->rendered_payload ?: 'n/a' }}</pre>
    </section>

    <section class="panel">
        <h2>Response Metadata</h2>
        <pre>{{ json_encode($delivery->response_meta ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>
@endsection
