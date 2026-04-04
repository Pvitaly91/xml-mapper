<?php

namespace App\Services\Feeds;

use App\Models\FeedHypercareWindow;
use App\Models\FeedProfile;
use App\Services\Ops\HypercarePolicyService;
use App\Services\Ops\NotificationCenterService;
use App\Services\Ops\OpsAlertService;
use App\Services\Ops\OpsMaintenanceStatusService;
use App\Services\Ops\OpsStatusService;
use App\Services\Ops\SilenceWindowService;
use App\Services\Ops\SloSummaryService;
use App\Services\Launch\MerchantLaunchService;

class FeedHypercareDashboardService
{
    public function __construct(
        private readonly FeedHypercareService $hypercareService,
        private readonly HypercarePolicyService $policyService,
        private readonly OpsAlertService $alertService,
        private readonly FeedbackSlaService $feedbackSlaService,
        private readonly FeedStabilityService $stabilityService,
        private readonly FeedReleaseReadinessService $readinessService,
        private readonly FeedLiveTimelineService $timelineService,
        private readonly OpsStatusService $opsStatusService,
        private readonly OpsMaintenanceStatusService $opsMaintenanceStatusService,
        private readonly SloSummaryService $sloSummaryService,
        private readonly SilenceWindowService $silenceWindowService,
        private readonly MerchantLaunchService $merchantLaunchService,
        private readonly NotificationCenterService $notificationCenterService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        $feedProfile->loadMissing(['sourceConnection.latestImport', 'publishedGeneration', 'latestGeneration', 'currentMerchantLaunch']);
        $hypercare = $this->hypercareService->current($feedProfile);
        $monitoring = $this->policyService->review($feedProfile, $hypercare);
        $alerts = $this->alertService->openAlertsForProfile($feedProfile, $hypercare);
        $feedback = $this->feedbackSlaService->summarize($feedProfile, $hypercare);
        $stability = $this->stabilityService->evaluate($feedProfile, $hypercare);
        $publishedGeneration = $feedProfile->publishedGeneration;
        $currentLaunch = $feedProfile->currentMerchantLaunch;

        return [
            'feed_profile' => $feedProfile,
            'hypercare' => $hypercare,
            'monitoring' => $monitoring,
            'alerts' => $alerts,
            'blocking_alerts' => $alerts->where('severity', 'critical')->values(),
            'feedback' => $feedback,
            'stability' => $stability,
            'published_generation' => $publishedGeneration,
            'time_since_publish' => $publishedGeneration?->published_at?->diffForHumans(now(), true),
            'latest_smoke' => $publishedGeneration?->smokeChecks()->latest('checked_at')->first(),
            'latest_first_pull' => $feedProfile->firstPullVerifications()->latest('verified_at')->first(),
            'latest_sync' => $feedProfile->sourceConnection?->last_synced_at,
            'latest_sync_status' => $feedProfile->sourceConnection?->last_sync_status,
            'rejection_summary' => $feedback['grouped_reasons'] ?? [],
            'release_readiness' => $publishedGeneration ? $this->readinessService->evaluate($feedProfile, $publishedGeneration) : null,
            'slo' => $this->sloSummaryService->summarize($feedProfile->shop, $feedProfile),
            'ops' => $this->opsStatusService->snapshot($feedProfile->shop),
            'maintenance' => $this->opsMaintenanceStatusService->summarize($feedProfile->shop, $feedProfile),
            'active_silence_window' => $this->silenceWindowService->current($feedProfile),
            'timeline_preview' => $this->timelineService->events($feedProfile)->take(12)->values(),
            'history' => $this->hypercareService->summarize($feedProfile)['history'],
            'risk_state' => $this->resolveRiskState($hypercare, $alerts, $stability, $monitoring),
            'next_checks' => $monitoring['next_checks_due'],
            'current_launch' => $currentLaunch ? $this->merchantLaunchService->refresh($currentLaunch) : null,
            'launch_check' => $currentLaunch ? $this->merchantLaunchService->check($currentLaunch) : null,
            'notifications' => $this->notificationCenterService->deliverySummaryForFeedProfile($feedProfile),
        ];
    }

    private function resolveRiskState(
        ?FeedHypercareWindow $hypercare,
        $alerts,
        array $stability,
        array $monitoring
    ): string {
        if ($alerts->contains(fn ($alert) => $alert->severity === 'critical')) {
            return 'critical';
        }

        if (($monitoring['current_risk_state'] ?? 'ok') === 'warning' || $stability['status'] === 'watch') {
            return 'warning';
        }

        if ($hypercare?->status === FeedHypercareWindow::STATUS_DEGRADED || in_array($stability['status'], ['degraded', 'unstable'], true)) {
            return 'critical';
        }

        return 'ok';
    }
}
