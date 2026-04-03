<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use App\Models\FeedProfileCutover;
use App\Models\User;
use App\Services\Ops\HypercarePolicyService;
use App\Services\Ops\OpsAlertService;
use RuntimeException;

class FeedHypercareService
{
    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
        private readonly HypercarePolicyService $policyService,
        private readonly FeedStabilityService $stabilityService,
        private readonly FeedHypercareReportService $reportService,
        private readonly OpsAlertService $alertService,
    ) {}

    public function current(FeedProfile $feedProfile): ?FeedHypercareWindow
    {
        return $feedProfile->currentHypercareWindow()->first();
    }

    public function start(FeedProfile $feedProfile, int $hours = 24, ?string $note = null, ?User $user = null): FeedHypercareWindow
    {
        if ($this->current($feedProfile) instanceof FeedHypercareWindow) {
            throw new RuntimeException('A hypercare window is already open for this feed profile.');
        }

        $generation = $feedProfile->publishedGeneration ?? $feedProfile->latestGeneration;
        $status = $this->initialStatus($feedProfile);
        $window = FeedHypercareWindow::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation?->id,
            'initiated_by_user_id' => $user?->id,
            'owner_user_id' => $user?->id,
            'status' => $status,
            'target_sla_minutes' => (int) $feedProfile->hypercareSettings()['target_sla_minutes'],
            'monitoring_cadence_minutes' => (int) $feedProfile->hypercareSettings()['monitoring_cadence_minutes'],
            'note' => $note,
            'started_at' => $status === FeedHypercareWindow::STATUS_ACTIVE ? now() : null,
            'planned_end_at' => now()->addHours(max(1, $hours)),
        ]);

        $this->auditService->record(
            $feedProfile,
            $generation,
            match ($status) {
                FeedHypercareWindow::STATUS_ACTIVE => 'hypercare_started',
                FeedHypercareWindow::STATUS_ARMED => 'hypercare_armed',
                default => 'hypercare_planned',
            },
            $user,
            $note,
            [
                'hypercare_window_id' => $window->id,
                'status' => $window->status,
                'planned_end_at' => $window->planned_end_at?->toIso8601String(),
            ]
        );

        if ($status === FeedHypercareWindow::STATUS_ACTIVE) {
            $this->policyService->review($feedProfile, $window);
        }

        return $window->fresh(['owner', 'initiatedBy', 'feedGeneration']);
    }

    public function ensureActiveAfterPublish(
        FeedProfile $feedProfile,
        FeedGeneration $generation,
        ?User $user = null,
        ?string $note = null
    ): FeedHypercareWindow {
        $window = $this->current($feedProfile);

        if (! $window instanceof FeedHypercareWindow) {
            $window = $this->start(
                $feedProfile->fresh(['publishedGeneration', 'latestGeneration']),
                (int) config('feed_mediator.hypercare.default_hours', 24),
                $note,
                $user
            );
        }

        $previousStatus = $window->status;

        $window->forceFill([
            'feed_generation_id' => $generation->id,
            'status' => FeedHypercareWindow::STATUS_ACTIVE,
            'started_at' => $window->started_at ?? ($generation->published_at ?? now()),
            'planned_end_at' => $window->planned_end_at ?? now()->addHours((int) config('feed_mediator.hypercare.default_hours', 24)),
            'note' => $note ?: $window->note,
        ])->save();

        if ($previousStatus !== FeedHypercareWindow::STATUS_ACTIVE) {
            $this->auditService->record(
                $feedProfile,
                $generation,
                'hypercare_activated',
                $user,
                $note,
                [
                    'hypercare_window_id' => $window->id,
                    'from_status' => $previousStatus,
                    'to_status' => $window->status,
                ]
            );
        }

        $this->policyService->review($feedProfile, $window);

        return $window->fresh();
    }

    public function extend(FeedHypercareWindow $window, int $hours = 24, ?string $note = null, ?User $user = null): FeedHypercareWindow
    {
        if ($window->isTerminal()) {
            throw new RuntimeException('Cannot extend a closed hypercare window.');
        }

        $window->forceFill([
            'status' => FeedHypercareWindow::STATUS_EXTENDED,
            'planned_end_at' => ($window->planned_end_at ?? now())->copy()->addHours(max(1, $hours)),
            'note' => $note ?: $window->note,
        ])->save();

        $this->auditService->record(
            $window->feedProfile,
            $window->feedGeneration,
            'hypercare_extended',
            $user,
            $note,
            [
                'hypercare_window_id' => $window->id,
                'planned_end_at' => $window->planned_end_at?->toIso8601String(),
            ]
        );

        return $window->fresh();
    }

    public function abort(FeedHypercareWindow $window, string $reason, ?User $user = null): FeedHypercareWindow
    {
        if (blank($reason)) {
            throw new RuntimeException('A reason is required to abort hypercare.');
        }

        $window->forceFill([
            'status' => FeedHypercareWindow::STATUS_ABORTED,
            'actual_end_at' => now(),
            'note' => $reason,
        ])->save();

        $this->auditService->record(
            $window->feedProfile,
            $window->feedGeneration,
            'hypercare_aborted',
            $user,
            $reason,
            [
                'hypercare_window_id' => $window->id,
            ]
        );

        return $window->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function close(FeedHypercareWindow $window, string $reason, ?User $user = null): array
    {
        $feedProfile = $window->feedProfile;
        $this->policyService->review($feedProfile, $window);

        if ($this->alertService->blockingAlertsForProfile($feedProfile, $window)->isNotEmpty()) {
            throw new RuntimeException('Resolve all critical hypercare incidents before closing hypercare.');
        }

        $stability = $this->stabilityService->evaluate($feedProfile, $window);
        $report = $this->reportService->closeout($feedProfile, $window);

        $window->forceFill([
            'status' => FeedHypercareWindow::STATUS_COMPLETED,
            'actual_end_at' => now(),
            'note' => $reason ?: $window->note,
            'meta' => array_merge($window->meta ?? [], [
                'closeout_report' => [
                    'path' => $report['path'],
                    'generated_at' => now()->toIso8601String(),
                    'stability' => $stability,
                ],
            ]),
        ])->save();

        $this->auditService->record(
            $feedProfile,
            $window->feedGeneration,
            'hypercare_closed',
            $user,
            $reason,
            [
                'hypercare_window_id' => $window->id,
                'stability_status' => $stability['status'],
                'stability_score' => $stability['score'],
                'closeout_report_path' => $report['path'],
            ]
        );

        return [
            'window' => $window->fresh(),
            'stability' => $stability,
            'report' => $report,
        ];
    }

    public function addNote(FeedProfile $feedProfile, string $body, ?User $user = null, ?FeedHypercareWindow $window = null): void
    {
        $window ??= $this->current($feedProfile);

        $this->auditService->record(
            $feedProfile,
            $window?->feedGeneration ?? $feedProfile->publishedGeneration ?? $feedProfile->latestGeneration,
            'hypercare_note_added',
            $user,
            $body,
            [
                'hypercare_window_id' => $window?->id,
                'body' => $body,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        $current = $this->current($feedProfile);

        return [
            'current' => $current,
            'history' => $feedProfile->hypercareWindows()
                ->with(['owner', 'initiatedBy', 'feedGeneration'])
                ->latest('id')
                ->limit(10)
                ->get(),
        ];
    }

    private function initialStatus(FeedProfile $feedProfile): string
    {
        if ($feedProfile->publishedGeneration instanceof FeedGeneration) {
            return FeedHypercareWindow::STATUS_ACTIVE;
        }

        if ($feedProfile->latestGeneration instanceof FeedGeneration || $feedProfile->currentCutover instanceof FeedProfileCutover) {
            return FeedHypercareWindow::STATUS_ARMED;
        }

        return FeedHypercareWindow::STATUS_PLANNED;
    }
}
