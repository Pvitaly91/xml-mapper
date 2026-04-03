<?php

namespace App\Services\Feeds;

use App\Models\FeedbackRecord;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\SourceConnection;
use App\Models\SyncLog;
use App\Services\Ops\EnvironmentContextService;
use App\Services\Ops\OpsMaintenanceStatusService;
use App\Services\Ops\OpsStatusService;
use App\Services\Ops\RestoreDrillService;
use App\Services\Ops\SloSummaryService;

class FeedOperationsService
{
    public function __construct(
        private readonly FeedCutoverService $cutoverService,
        private readonly FeedFirstPullVerificationService $firstPullVerificationService,
        private readonly FeedPublishWindowService $publishWindowService,
        private readonly OpsStatusService $opsStatusService,
        private readonly OpsMaintenanceStatusService $opsMaintenanceStatusService,
        private readonly FeedReleaseReadinessService $readinessService,
        private readonly FeedRehearsalService $rehearsalService,
        private readonly RestoreDrillService $restoreDrillService,
        private readonly SloSummaryService $sloSummaryService,
        private readonly EnvironmentContextService $environmentContextService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        $feedProfile->loadMissing([
            'sourceConnection.latestImport',
            'latestGeneration',
            'publishedGeneration',
            'currentCutover.targetGeneration',
            'currentCutover.publishedGeneration',
        ]);

        $latestGeneration = $feedProfile->latestGeneration;
        $publishedGeneration = $feedProfile->publishedGeneration;
        $latestPreviewEvent = $feedProfile->releaseEvents()->where('action', 'preview_link_created')->latest('occurred_at')->first();
        $latestRollback = $feedProfile->releaseEvents()->where('action', 'rolled_back')->latest('occurred_at')->first();
        $ops = $this->opsStatusService->snapshot($feedProfile->shop);
        $maintenance = $this->opsMaintenanceStatusService->summarize($feedProfile->shop, $feedProfile);
        $cutover = $this->cutoverService->summarize($feedProfile, $latestGeneration);
        $firstPull = $this->firstPullVerificationService->summarize($feedProfile);

        return [
            'feed_profile' => $feedProfile,
            'source_connection' => $feedProfile->sourceConnection,
            'latest_generation' => $latestGeneration,
            'published_generation' => $publishedGeneration,
            'last_sync' => $feedProfile->sourceConnection?->last_synced_at,
            'last_build' => $latestGeneration?->built_at,
            'last_publish' => $publishedGeneration?->published_at,
            'last_preview_event' => $latestPreviewEvent,
            'last_smoke_check' => $publishedGeneration?->smokeChecks()->latest('checked_at')->first(),
            'first_pull' => $firstPull,
            'cutover' => $cutover,
            'publish_window' => $this->publishWindowService->evaluate($feedProfile),
            'broken_source_auth' => in_array($feedProfile->sourceConnection?->last_connection_check_status, [
                SourceConnection::CHECK_STATUS_AUTH_FAILED,
                SourceConnection::CHECK_STATUS_CONFIG_ERROR,
            ], true) || in_array($feedProfile->sourceConnection?->last_sync_status, [
                SourceConnection::CHECK_STATUS_AUTH_FAILED,
                SourceConnection::CHECK_STATUS_CONFIG_ERROR,
            ], true),
            'failed_jobs_count' => $ops['failed_jobs']['count'] ?? 0,
            'maintenance' => $maintenance,
            'environment' => $this->environmentContextService->summary(),
            'rehearsal' => $this->rehearsalService->summarize($feedProfile),
            'restore_drill' => $this->restoreDrillService->summarize($feedProfile),
            'slo' => $this->sloSummaryService->summarize($feedProfile->shop, $feedProfile),
            'feedback_summary' => [
                'imports' => $feedProfile->feedbackImports()->count(),
                'accepted' => $feedProfile->feedbackRecords()->where('status', FeedbackRecord::STATUS_ACCEPTED)->count(),
                'rejected' => $feedProfile->feedbackRecords()->where('status', FeedbackRecord::STATUS_REJECTED)->count(),
                'warnings' => $feedProfile->feedbackRecords()->where('status', FeedbackRecord::STATUS_WARNING)->count(),
                'open' => $feedProfile->feedbackRecords()->where('resolution_status', FeedbackRecord::RESOLUTION_OPEN)->count(),
            ],
            'latest_notifications' => SyncLog::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->whereIn('level', ['warning', 'error'])
                ->latest('occurred_at')
                ->limit(12)
                ->get(),
            'latest_incidents' => $feedProfile->releaseEvents()
                ->with('user')
                ->whereIn('action', [
                    'publish_blocked',
                    'publish_failed',
                    'rolled_back',
                    'first_pull_verification_failed',
                    'rehearsal_failed',
                    'rehearsal_passed',
                    'preview_link_created',
                    'feedback_imported',
                ])
                ->latest('occurred_at')
                ->limit(12)
                ->get(),
            'last_rollback' => $latestRollback,
            'release_readiness' => $latestGeneration instanceof FeedGeneration
                ? $this->readinessService->evaluate($feedProfile, $latestGeneration)
                : null,
        ];
    }
}
