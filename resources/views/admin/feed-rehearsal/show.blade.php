@extends('layouts.admin', ['title' => $feedProfile->name.' Staging Rehearsal'])

@section('subtitle', 'Formal rehearsal workflow for staging deploy verification, candidate QA, canary rehearsal, and rollback readiness.')

@section('content')
    <section class="panel">
        <div class="toolbar">
            <a class="button" href="{{ route('admin.feed-profiles.operations.show', $feedProfile) }}">Back to operations</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.release-center', $feedProfile) }}">Release center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.promotion.show', $feedProfile) }}">Promotion center</a>
            <a class="button secondary" href="{{ route('admin.feed-profiles.launch-pack.show', $feedProfile) }}">Launch pack</a>
        </div>
        <div class="detail-list">
            <div class="detail-row"><strong>Latest rehearsal</strong><div>{{ optional($rehearsal['latest']?->started_at)->format('Y-m-d H:i:s') ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Status</strong><div>{{ $rehearsal['status'] }}</div></div>
            <div class="detail-row"><strong>Current step</strong><div>{{ $rehearsal['current_step'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Canary preview</strong><div>{{ $rehearsal['preview_url'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Rollback preview</strong><div>{{ $rehearsal['rollback_preview_url'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>QA bundle path</strong><div>{{ $rehearsal['qa_bundle_path'] ?: 'n/a' }}</div></div>
            <div class="detail-row"><strong>Promotion status</strong><div>{{ $rehearsal['promotion']['status'] }}</div></div>
            <div class="detail-row"><strong>Promotion drift</strong><div>{{ $rehearsal['promotion']['drift_status'] }}</div></div>
            <div class="detail-row"><strong>Secret rebind</strong><div>{{ $rehearsal['promotion']['secret_rebind_pending'] ? 'pending' : 'clear' }}</div></div>
        </div>
    </section>

    <section class="panel">
        <h2>Run Rehearsal</h2>
        <form method="POST" action="{{ route('admin.feed-profiles.rehearsal.store', $feedProfile) }}">
            @csrf
            <div class="checks">
                <label class="check"><input type="checkbox" name="with_sync" value="1"> Preflight + sync</label>
                <label class="check"><input type="checkbox" name="with_build" value="1"> Build candidate</label>
                <label class="check"><input type="checkbox" name="with_preview" value="1" checked> Generate preview</label>
                <label class="check"><input type="checkbox" name="with_smoke" value="1" checked> Smoke + first-pull check</label>
                <label class="check"><input type="checkbox" name="with_rollback_check" value="1"> Rollback rehearsal</label>
            </div>
            <button class="button secondary" type="submit" style="margin-top: 16px;">Run staging rehearsal</button>
        </form>
    </section>

    <div class="grid cols-2">
        <section class="panel">
            <h2>Blocking Issues</h2>
            @if(($rehearsal['blocking_issues'] ?? []) !== [])
                <ul>
                    @foreach($rehearsal['blocking_issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No blocking issues.</p>
            @endif
        </section>
        <section class="panel">
            <h2>Warnings</h2>
            @if(($rehearsal['warnings'] ?? []) !== [])
                <ul>
                    @foreach($rehearsal['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No warnings.</p>
            @endif
        </section>
    </div>

    <section class="panel">
        <h2>Steps</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Step</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                @forelse($rehearsal['steps'] as $step)
                    <tr>
                        <td>{{ $step['key'] }}</td>
                        <td>{{ $step['status'] }}</td>
                        <td>
                            @foreach($step['errors'] as $error)
                                <div class="muted">{{ $error }}</div>
                            @endforeach
                            @foreach($step['warnings'] as $warning)
                                <div class="muted">{{ $warning }}</div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No rehearsal has been recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
