@extends('layouts.admin', ['title' => 'Generation #'.$generation->id])

@section('subtitle', 'Release readiness, smoke checks, publish guardrails, diff, and operator actions.')

@section('content')
    @php($summary = $generation->meta['summary'] ?? [])
    @php($guard = $generation->meta['publish_guard'] ?? ['allowed' => false, 'reasons' => [], 'summary' => []])
    @php($diffSummary = $diffReport['summary'] ?? [])

    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Back to release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.show', $feedProfile) }}">Back to profile</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.reports.invalid-items', ['feed_profile' => $feedProfile, 'generation_id' => $generation->id]) }}">Invalid items CSV</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.generations.reports.diff', [$feedProfile, $generation]) }}">Diff JSON</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.generations.reports.readiness', [$feedProfile, $generation]) }}">Readiness JSON</a>
            @if($publicFeedUrl)
                <a class="button link" href="{{ $publicFeedUrl }}" target="_blank" rel="noreferrer">Open published feed</a>
            @endif
        </div>

        <div class="detail-list">
            <div class="detail-row"><strong>Status</strong><div>{{ $generation->status }}</div></div>
            <div class="detail-row"><strong>Release status</strong><div>{{ $generation->release_status }}</div></div>
            <div class="detail-row"><strong>Built at</strong><div>{{ optional($generation->built_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Approved at</strong><div>{{ optional($generation->approved_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Approved by</strong><div>{{ $generation->approvedBy?->email ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Published at</strong><div>{{ optional($generation->published_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Counts</strong><div>ready {{ $summary['ready'] ?? 0 }}, invalid {{ $summary['invalid_total'] ?? 0 }}, excluded {{ $summary['excluded'] ?? 0 }}</div></div>
            <div class="detail-row"><strong>Smoke check</strong><div>{{ $generation->last_smoke_check_status ?: 'n/a' }} @if($generation->last_smoke_check_at) ({{ $generation->last_smoke_check_at->format('Y-m-d H:i:s') }}) @endif</div></div>
        </div>
    </section>

    <section class="panel">
        <h2>Actions</h2>
        <div class="toolbar">
            @if($generation->status === 'built' && $generation->release_status === 'built')
                <form method="POST" action="{{ route('admin.feed-profiles.generations.candidate', [$feedProfile, $generation]) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Optional candidate note">
                    <button class="button" type="submit">Mark candidate</button>
                </form>
            @endif

            @if(in_array($generation->status, ['built', 'published'], true) && ! in_array($generation->release_status, ['approved', 'published'], true))
                <form method="POST" action="{{ route('admin.feed-profiles.generations.approve', [$feedProfile, $generation]) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="Optional approval note">
                    <button class="button secondary" type="submit">Approve</button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.feed-profiles.publish', $feedProfile) }}">
                @csrf
                <input type="hidden" name="generation_id" value="{{ $generation->id }}">
                <button class="button" type="submit">Publish</button>
            </form>

            <form method="POST" action="{{ route('admin.feed-profiles.publish', $feedProfile) }}">
                @csrf
                <input type="hidden" name="generation_id" value="{{ $generation->id }}">
                <input type="hidden" name="force_publish" value="1">
                <input type="text" name="reason" placeholder="Force publish reason" required>
                <button class="button warning" type="submit">Force publish</button>
            </form>

            <form method="POST" action="{{ route('admin.feed-profiles.generations.smoke-check', [$feedProfile, $generation]) }}">
                @csrf
                <input type="text" name="reason" placeholder="Optional smoke-check note">
                <button class="button secondary" type="submit">Rerun smoke check</button>
            </form>

            @if($feedProfile->publishedGeneration && $feedProfile->publishedGeneration->id !== $generation->id)
                <form method="POST" action="{{ route('admin.feed-profiles.rollback', $feedProfile) }}">
                    @csrf
                    <input type="hidden" name="to_generation_id" value="{{ $generation->id }}">
                    <input type="text" name="reason" placeholder="Rollback reason" required>
                    <button class="button danger" type="submit">Rollback to this generation</button>
                </form>
            @endif
        </div>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Readiness</h2>
                <span class="badge {{ $readiness['status'] === 'ready' ? 'ok' : ($readiness['status'] === 'blocked' ? 'err' : 'warn') }}">{{ $readiness['status'] }}</span>
            </div>
            <h3>Blocking issues</h3>
            @if($readiness['blocking_issues'] !== [])
                <ul>
                    @foreach($readiness['blocking_issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No blocking issues.</p>
            @endif

            <h3>Warnings</h3>
            @if($readiness['warnings'] !== [])
                <ul>
                    @foreach($readiness['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No warnings.</p>
            @endif

            <h3>Next steps</h3>
            @if($readiness['next_steps'] !== [])
                <ul>
                    @foreach($readiness['next_steps'] as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No extra steps.</p>
            @endif
        </section>

        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Publish Guard</h2>
                <span class="badge {{ ($guard['allowed'] ?? false) ? 'ok' : 'warn' }}">{{ ($guard['allowed'] ?? false) ? 'allowed' : 'blocked' }}</span>
            </div>
            <div class="detail-list">
                <div class="detail-row"><strong>Ready items</strong><div>{{ $guard['summary']['ready_items'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Invalid items</strong><div>{{ $guard['summary']['invalid_items'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Invalid ratio</strong><div>{{ $guard['summary']['invalid_ratio'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Minimum ready items</strong><div>{{ $guard['summary']['minimum_ready_items'] ?? 0 }}</div></div>
            </div>
            @if(($guard['reasons'] ?? []) !== [])
                <ul style="margin-top: 14px;">
                    @foreach($guard['reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Generation Diff</h2>
            <div class="detail-list">
                <div class="detail-row"><strong>Added items</strong><div>{{ $diffSummary['added_items_total'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Removed items</strong><div>{{ $diffSummary['removed_items_total'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Changed items</strong><div>{{ $diffSummary['changed_items_total'] ?? 0 }}</div></div>
                <div class="detail-row"><strong>Changed fields</strong><div>price {{ $diffSummary['changed_fields']['price'] ?? 0 }}, availability {{ $diffSummary['changed_fields']['availability'] ?? 0 }}, categoryId {{ $diffSummary['changed_fields']['categoryId'] ?? 0 }}, vendorCode {{ $diffSummary['changed_fields']['vendorCode'] ?? 0 }}</div></div>
            </div>

            @if(($diffReport['changed_items'] ?? []) !== [])
                <h3 style="margin-top: 18px;">Changed items sample</h3>
                <ul>
                    @foreach($diffReport['changed_items'] as $item)
                        <li>{{ $item['offer_id'] }}: {{ collect($item['changes'])->pluck('field')->implode(', ') }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel">
            <div class="toolbar">
                <h2 style="margin: 0;">Smoke Check</h2>
                <span class="badge {{ $latestSmokeCheck?->status === 'ok' ? 'ok' : ($latestSmokeCheck?->status === 'failed' ? 'err' : 'warn') }}">{{ $latestSmokeCheck?->status ?: 'n/a' }}</span>
            </div>
            @if($latestSmokeCheck)
                <div class="detail-list">
                    <div class="detail-row"><strong>Checked at</strong><div>{{ optional($latestSmokeCheck->checked_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>HTTP status</strong><div>{{ $latestSmokeCheck->http_status ?: 'n/a' }}</div></div>
                    <div class="detail-row"><strong>Latency</strong><div>{{ $latestSmokeCheck->latency_ms ?: 0 }} ms</div></div>
                    <div class="detail-row"><strong>Offers / categories</strong><div>{{ $latestSmokeCheck->offers_total ?: 0 }} / {{ $latestSmokeCheck->categories_total ?: 0 }}</div></div>
                    <div class="detail-row"><strong>Checksums</strong><div>{{ $latestSmokeCheck->response_checksum ?: 'n/a' }}</div></div>
                </div>

                @if(($latestSmokeCheck->errors ?? []) !== [])
                    <h3 style="margin-top: 18px;">Errors</h3>
                    <ul>
                        @foreach($latestSmokeCheck->errors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                @if(($latestSmokeCheck->warnings ?? []) !== [])
                    <h3>Warnings</h3>
                    <ul>
                        @foreach($latestSmokeCheck->warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="muted">Smoke check has not been run for this generation yet.</p>
            @endif
        </section>
    </div>

    <section class="panel">
        <h2>Release Audit Trail</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>User</th>
                    <th>Reason</th>
                    <th>Context</th>
                </tr>
                </thead>
                <tbody>
                @forelse($releaseEvents as $event)
                    <tr>
                        <td>{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</td>
                        <td>{{ $event->action }}</td>
                        <td>{{ $event->user?->email ?: 'system' }}</td>
                        <td>{{ $event->reason ?: 'n/a' }}</td>
                        <td><pre>{{ json_encode($event->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No release events recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
