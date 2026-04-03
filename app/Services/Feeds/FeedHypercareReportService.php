<?php

namespace App\Services\Feeds;

use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use App\Models\OpsAlert;
use App\Services\Ops\HypercarePolicyService;
use App\Services\Ops\OpsAlertService;
use Illuminate\Support\Facades\Storage;

class FeedHypercareReportService
{
    public function __construct(
        private readonly FeedLiveTimelineService $timelineService,
        private readonly FeedbackSlaService $feedbackSlaService,
        private readonly FeedStabilityService $stabilityService,
        private readonly HypercarePolicyService $policyService,
        private readonly OpsAlertService $alertService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dailyDigest(FeedProfile $feedProfile, ?string $date = null): array
    {
        $date = $date ?: now()->toDateString();
        $monitoring = $this->policyService->review($feedProfile, $feedProfile->currentHypercareWindow);
        $timeline = $this->timelineService->events($feedProfile, ['from' => $date, 'to' => $date]);
        $feedback = $this->feedbackSlaService->summarize($feedProfile, $feedProfile->currentHypercareWindow);
        $alerts = $this->alertService->openAlertsForProfile($feedProfile, $feedProfile->currentHypercareWindow);

        $lines = [
            '# Daily Hypercare Digest',
            '',
            'Feed profile: '.$feedProfile->name.' ('.$feedProfile->code.')',
            'Date: '.$date,
            '',
            '## Sync / Build / Publish',
            '- Recent timeline events: '.$timeline->whereIn('event_type', ['sync_log', 'release_event'])->count(),
            '',
            '## Smoke / First Pull',
            '- Smoke cadence status: '.$this->policyStatus($monitoring['results'], 'smoke_checks_cadence'),
            '- First-pull cadence status: '.$this->policyStatus($monitoring['results'], 'first_pull_cadence'),
            '',
            '## Alerts',
            '- Open alerts: '.$alerts->count(),
            '- Critical alerts: '.$alerts->where('severity', OpsAlert::SEVERITY_CRITICAL)->count(),
            '',
            '## Feedback / Rejections',
            '- Rejected: '.($feedback['rejected_total'] ?? 0),
            '- Pending backlog: '.($feedback['pending_backlog'] ?? 0),
            '',
            '## Unresolved Blockers',
        ];

        foreach ($alerts->where('severity', OpsAlert::SEVERITY_CRITICAL)->take(5) as $alert) {
            $lines[] = '- '.$alert->title.': '.$alert->message;
        }

        if ($alerts->where('severity', OpsAlert::SEVERITY_CRITICAL)->isEmpty()) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Recent Manual Actions';

        foreach ($timeline->where('event_type', 'release_event')->take(8) as $event) {
            $lines[] = '- ['.$event['occurred_at'].'] '.$event['title'].' - '.$event['message'];
        }

        if ($timeline->where('event_type', 'release_event')->isEmpty()) {
            $lines[] = '- No manual actions recorded.';
        }

        $content = implode(PHP_EOL, $lines).PHP_EOL;

        return $this->storeReport($feedProfile, 'digest-'.$date.'.md', $content, 'daily_digest');
    }

    /**
     * @return array<string, mixed>
     */
    public function shiftHandoff(FeedProfile $feedProfile): array
    {
        $hypercare = $feedProfile->currentHypercareWindow;
        $monitoring = $this->policyService->review($feedProfile, $hypercare);
        $stability = $this->stabilityService->evaluate($feedProfile, $hypercare);
        $alerts = $this->alertService->openAlertsForProfile($feedProfile, $hypercare);
        $lines = [
            '# Hypercare Shift Handoff',
            '',
            'Feed profile: '.$feedProfile->name.' ('.$feedProfile->code.')',
            'Hypercare status: '.($hypercare?->status ?? 'not_started'),
            'Current risk state: '.$monitoring['current_risk_state'],
            'Stability score: '.$stability['score'].' ('.$stability['status'].')',
            '',
            '## Open Incidents',
        ];

        foreach ($alerts->take(10) as $alert) {
            $lines[] = '- ['.$alert->severity.'] '.$alert->title.' ('.$alert->state.')';
        }

        if ($alerts->isEmpty()) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Pending Actions';

        foreach ($stability['recommended_next_steps'] as $step) {
            $lines[] = '- '.$step;
        }

        if ($stability['recommended_next_steps'] === []) {
            $lines[] = '- No pending actions.';
        }

        $lines[] = '';
        $lines[] = '## Next Checks Due';

        foreach ($monitoring['next_checks_due'] as $check) {
            $lines[] = '- '.$check['policy_key'].' at '.optional($check['due_at'])->toIso8601String();
        }

        if ($monitoring['next_checks_due'] === []) {
            $lines[] = '- No scheduled checks.';
        }

        $content = implode(PHP_EOL, $lines).PHP_EOL;

        return $this->storeReport($feedProfile, 'handoff-'.now()->format('YmdHis').'.md', $content, 'shift_handoff');
    }

    /**
     * @return array<string, mixed>
     */
    public function closeout(FeedProfile $feedProfile, FeedHypercareWindow $hypercare): array
    {
        $monitoring = $this->policyService->review($feedProfile, $hypercare);
        $timeline = $this->timelineService->events($feedProfile, [
            'from' => optional($hypercare->started_at)->toDateString(),
            'to' => optional($hypercare->actual_end_at ?? now())->toDateString(),
        ]);
        $stability = $this->stabilityService->evaluate($feedProfile, $hypercare);
        $alerts = $this->alertService->openAlertsForProfile($feedProfile, $hypercare);
        $feedback = $this->feedbackSlaService->summarize($feedProfile, $hypercare);
        $lines = [
            '# Hypercare Closeout',
            '',
            'Feed profile: '.$feedProfile->name.' ('.$feedProfile->code.')',
            'Window: '.optional($hypercare->started_at)->toIso8601String().' -> '.optional($hypercare->actual_end_at ?? now())->toIso8601String(),
            'Final status: '.$hypercare->status,
            'Stability: '.$stability['score'].' ('.$stability['status'].')',
            '',
            '## What Happened',
        ];

        foreach ($timeline->take(15) as $event) {
            $lines[] = '- ['.$event['occurred_at'].'] '.$event['title'].' - '.$event['message'];
        }

        $lines[] = '';
        $lines[] = '## Incidents';

        foreach ($alerts->take(15) as $alert) {
            $lines[] = '- ['.$alert->severity.'] '.$alert->title.' - '.$alert->state;
        }

        if ($alerts->isEmpty()) {
            $lines[] = '- No open incidents.';
        }

        $lines[] = '';
        $lines[] = '## Resolutions';
        $lines[] = '- Feedback fixed: '.($feedback['fixed'] ?? 0);
        $lines[] = '- Feedback wont_fix: '.($feedback['wont_fix'] ?? 0);
        $lines[] = '';
        $lines[] = '## Remaining Risks';

        foreach ($monitoring['blocking_results'] as $result) {
            $lines[] = '- '.$result['summary'];
        }

        if ($monitoring['blocking_results'] === []) {
            $lines[] = '- No critical monitoring blockers remain.';
        }

        $lines[] = '';
        $lines[] = '## Recommended Next Steps';

        foreach ($stability['recommended_next_steps'] as $step) {
            $lines[] = '- '.$step;
        }

        if ($stability['recommended_next_steps'] === []) {
            $lines[] = '- Continue standard post-launch operations.';
        }

        $content = implode(PHP_EOL, $lines).PHP_EOL;

        return $this->storeReport($feedProfile, 'closeout-'.$hypercare->id.'.md', $content, 'closeout');
    }

    /**
     * @return array<string, mixed>
     */
    private function storeReport(FeedProfile $feedProfile, string $filename, string $content, string $type): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.runbooks_directory'), '/')
            .'/shop-'.$feedProfile->shop_id
            .'/feed-'.$feedProfile->id
            .'/hypercare/'.$filename;
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);

        return [
            'type' => $type,
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'content' => $content,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function policyStatus(array $results, string $key): string
    {
        return collect($results)->firstWhere('policy_key', $key)['status'] ?? 'n/a';
    }
}
