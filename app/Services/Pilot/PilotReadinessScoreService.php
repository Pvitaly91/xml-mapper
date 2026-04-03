<?php

namespace App\Services\Pilot;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationSignoff;
use App\Models\FeedProfile;
use App\Models\PilotRun;
use App\Models\SourceConnection;
use App\Models\ValidationError;
use App\Services\Feeds\FeedbackSlaService;
use App\Services\Feeds\FeedFirstPullVerificationService;
use App\Services\Feeds\FeedPilotReadinessService;
use App\Services\Feeds\FeedReleaseReadinessService;
use App\Services\Feeds\FeedSignoffService;
use App\Services\Feeds\FeedStabilityService;
use App\Services\Promotion\PromotionStatusService;

class PilotReadinessScoreService
{
    public function __construct(
        private readonly FeedPilotReadinessService $pilotReadinessService,
        private readonly FeedReleaseReadinessService $releaseReadinessService,
        private readonly PromotionStatusService $promotionStatusService,
        private readonly FeedSignoffService $signoffService,
        private readonly FeedFirstPullVerificationService $firstPullVerificationService,
        private readonly FeedbackSlaService $feedbackSlaService,
        private readonly FeedStabilityService $feedStabilityService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function score(FeedProfile $feedProfile, ?PilotRun $pilotRun = null): array
    {
        $feedProfile->loadMissing([
            'sourceConnection.latestImport',
            'latestGeneration',
            'publishedGeneration',
            'currentHypercareWindow',
        ]);

        $latestGeneration = $pilotRun?->candidateGeneration ?? $feedProfile->latestGeneration;
        $publishedGeneration = $pilotRun?->publishedGeneration ?? $feedProfile->publishedGeneration;
        $pilotReadiness = $this->pilotReadinessService->summarize($feedProfile);
        $promotion = $this->promotionStatusService->summarize($feedProfile);
        $releaseReadiness = $latestGeneration instanceof FeedGeneration
            ? $this->releaseReadinessService->evaluate($feedProfile, $latestGeneration)
            : null;
        $signoff = $latestGeneration instanceof FeedGeneration
            ? $this->signoffService->evaluate($feedProfile, $latestGeneration)
            : [
                'allowed' => false,
                'required' => $feedProfile->signoffRequired(),
                'required_status' => $feedProfile->requiredSignoffStatus(),
                'current' => null,
                'reasons' => ['Build a candidate generation before sign-off.'],
            ];
        $firstPull = $this->firstPullVerificationService->summarize($feedProfile);
        $feedback = $this->feedbackSlaService->summarize($feedProfile, $feedProfile->currentHypercareWindow);
        $stability = $this->feedStabilityService->evaluate($feedProfile, $feedProfile->currentHypercareWindow);
        $criticalConformanceErrors = $latestGeneration instanceof FeedGeneration
            ? ValidationError::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->where('is_active', true)
                ->whereIn('code', ValidationError::criticalConformanceCodes())
                ->whereHas('feedItem', fn ($query) => $query->where('last_built_generation_id', $latestGeneration->id))
                ->count()
            : 0;

        $components = [
            'source_health' => $this->component(
                15,
                $this->sourceHealthScore($feedProfile->sourceConnection, $pilotReadiness),
                [
                    'connection_status' => $feedProfile->sourceConnection?->last_connection_check_status,
                    'sync_status' => $feedProfile->sourceConnection?->last_sync_status,
                    'latest_import_status' => $feedProfile->sourceConnection?->latestImport?->status,
                ]
            ),
            'mappings_completeness' => $this->component(
                10,
                ($pilotReadiness['mappings_complete']['ok'] ?? false) ? 10 : max(0, 10 - min(10, (int) ($pilotReadiness['mappings_complete']['invalid_mapping_items'] ?? 0))),
                $pilotReadiness['mappings_complete']
            ),
            'conformance' => $this->component(
                10,
                $criticalConformanceErrors === 0 ? 10 : max(0, 10 - min(10, $criticalConformanceErrors)),
                ['critical_errors' => $criticalConformanceErrors]
            ),
            'release_readiness' => $this->component(
                15,
                match ($releaseReadiness['status'] ?? 'blocked') {
                    'ready' => 15,
                    'warning' => 10,
                    default => 0,
                },
                $releaseReadiness ?? ['status' => 'blocked']
            ),
            'promotion_drift' => $this->component(
                10,
                match ($promotion['status'] ?? 'unknown') {
                    'in_sync', 'unknown' => 10,
                    'promotion_needed' => 4,
                    default => 0,
                },
                $promotion
            ),
            'secret_rebind' => $this->component(
                10,
                ($promotion['secret_rebind_pending'] ?? false) ? 0 : 10,
                [
                    'pending' => (bool) ($promotion['secret_rebind_pending'] ?? false),
                    'state' => $promotion['secret_state'] ?? null,
                ]
            ),
            'signoff' => $this->component(
                10,
                $this->signoffScore($signoff),
                [
                    'required' => (bool) ($signoff['required'] ?? false),
                    'required_status' => $signoff['required_status'] ?? FeedGenerationSignoff::STATUS_INTERNAL_APPROVED,
                    'current_status' => $signoff['current']?->status,
                ]
            ),
            'publish_verification' => $this->component(
                10,
                $this->publishVerificationScore($publishedGeneration, $firstPull['latest'] ?? null),
                [
                    'published_generation_id' => $publishedGeneration?->id,
                    'smoke_status' => $publishedGeneration?->last_smoke_check_status,
                    'first_pull_status' => $firstPull['latest']?->status,
                ]
            ),
            'feedback_backlog' => $this->component(
                5,
                ($feedback['pending_backlog'] ?? 0) === 0 ? 5 : max(0, 5 - min(5, (int) $feedback['pending_backlog'])),
                [
                    'pending_backlog' => $feedback['pending_backlog'] ?? 0,
                    'rejected_total' => $feedback['rejected_total'] ?? 0,
                ]
            ),
            'hypercare_stability' => $this->component(
                5,
                match ($stability['status'] ?? 'unstable') {
                    'stable' => 5,
                    'watch' => 3,
                    'degraded' => 1,
                    default => 0,
                },
                [
                    'status' => $stability['status'] ?? 'unstable',
                    'score' => $stability['score'] ?? 0,
                    'blockers' => $stability['blockers'] ?? [],
                ]
            ),
        ];

        $score = collect($components)->sum('score');
        $blockingReasons = array_values(array_filter([
            ($releaseReadiness && ($releaseReadiness['blocking_issues'] ?? []) !== []) ? implode(' ', $releaseReadiness['blocking_issues']) : null,
            ($promotion['status'] ?? null) === 'incompatible' ? 'Promotion drift is incompatible.' : null,
            ($promotion['secret_rebind_pending'] ?? false) ? 'Secret rebind is still pending.' : null,
            ! ($signoff['allowed'] ?? false) ? implode(' ', (array) ($signoff['reasons'] ?? [])) : null,
            ($publishedGeneration?->last_smoke_check_status === 'failed') ? 'Latest published smoke check failed.' : null,
            (($firstPull['latest']?->status ?? null) === 'failed') ? 'First-pull verification failed.' : null,
            ($feedback['pending_backlog'] ?? 0) > 0 ? 'Feedback remediation backlog is still open.' : null,
            (($stability['blockers'] ?? []) !== []) ? implode(' ', $stability['blockers']) : null,
        ]));
        $warnings = array_values(array_filter([
            ($promotion['drift_status'] ?? null) === 'drift_detected' ? 'Promotion drift is detected.' : null,
            (($feedback['warning_total'] ?? 0) > 0) ? 'Warning feedback rows are present.' : null,
            (($stability['status'] ?? null) === 'watch') ? 'Hypercare stability is watch-level.' : null,
        ]));
        $status = $this->status($score, $blockingReasons, $publishedGeneration, $feedback, $stability, $pilotRun);
        $fingerprint = hash('sha256', json_encode([
            'score' => $score,
            'status' => $status,
            'components' => $components,
            'blocking' => $blockingReasons,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'status' => $status,
            'score' => $score,
            'fingerprint' => $fingerprint,
            'components' => $components,
            'blocking_reasons' => $blockingReasons,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function component(int $max, int $score, array $details): array
    {
        return [
            'score' => max(0, min($max, $score)),
            'max' => $max,
            'details' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $pilotReadiness
     */
    private function sourceHealthScore(?SourceConnection $connection, array $pilotReadiness): int
    {
        if (! $connection instanceof SourceConnection) {
            return 0;
        }

        if (($pilotReadiness['source_synced']['ok'] ?? false) && $connection->last_connection_check_status === SourceConnection::CHECK_STATUS_OK) {
            return 15;
        }

        if (
            ! in_array($connection->last_connection_check_status, [SourceConnection::CHECK_STATUS_AUTH_FAILED, SourceConnection::CHECK_STATUS_CONFIG_ERROR], true)
            && ! in_array($connection->last_sync_status, [SourceConnection::CHECK_STATUS_AUTH_FAILED, SourceConnection::CHECK_STATUS_CONFIG_ERROR], true)
        ) {
            return 8;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $signoff
     */
    private function signoffScore(array $signoff): int
    {
        if (! ($signoff['required'] ?? false)) {
            return 10;
        }

        if ($signoff['allowed'] ?? false) {
            return 10;
        }

        if (($signoff['current']?->status ?? null) === FeedGenerationSignoff::STATUS_PENDING_REVIEW) {
            return 4;
        }

        return 0;
    }

    private function publishVerificationScore(?FeedGeneration $publishedGeneration, $firstPullVerification): int
    {
        if (! $publishedGeneration instanceof FeedGeneration) {
            return 0;
        }

        if ($publishedGeneration->last_smoke_check_status === 'ok' && ($firstPullVerification?->status ?? null) === 'ok') {
            return 10;
        }

        if (
            in_array($publishedGeneration->last_smoke_check_status, ['ok', 'warning'], true)
            && in_array($firstPullVerification?->status, ['ok', 'warning'], true)
        ) {
            return 6;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $feedback
     * @param  array<string, mixed>  $stability
     * @param  list<string>  $blockingReasons
     */
    private function status(
        int $score,
        array $blockingReasons,
        ?FeedGeneration $publishedGeneration,
        array $feedback,
        array $stability,
        ?PilotRun $pilotRun = null
    ): string {
        if (
            $publishedGeneration instanceof FeedGeneration
            && ($feedback['pending_backlog'] ?? 0) === 0
            && ($stability['status'] ?? null) === 'stable'
            && in_array($pilotRun?->state, [PilotRun::STATE_COMPLETED, PilotRun::STATE_HYPERCARE_ACTIVE], true)
        ) {
            return 'stable_after_launch';
        }

        if ($blockingReasons !== [] || $score < 50) {
            return 'not_ready';
        }

        if ($score < 80) {
            return 'needs_attention';
        }

        return 'ready';
    }
}
