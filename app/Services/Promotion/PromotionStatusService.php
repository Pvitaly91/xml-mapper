<?php

namespace App\Services\Promotion;

use App\Models\FeedProfile;
use App\Models\PromotionRun;

class PromotionStatusService
{
    public function __construct(
        private readonly PromotionSnapshotService $snapshotService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        $environmentClass = (string) config('feed_mediator.environment.class', config('app.env', 'local'));
        $environmentLabel = (string) (config('feed_mediator.environment.label') ?: ucfirst($environmentClass));
        $currentPayload = $this->snapshotService->payloadForProfile($feedProfile, $environmentClass, $environmentLabel);
        $currentChecksum = $this->snapshotService->checksumForPayload($currentPayload);
        $latestSnapshot = $feedProfile->promotionSnapshots()->latest('generated_at')->latest('id')->first();
        $latestSourceApply = $feedProfile->sourcePromotionRuns()
            ->where('mode', PromotionRun::MODE_APPLY)
            ->whereIn('status', [PromotionRun::STATUS_SUCCEEDED, PromotionRun::STATUS_WARNING])
            ->latest('finished_at')
            ->latest('id')
            ->first();
        $latestTargetApply = $feedProfile->targetPromotionRuns()
            ->where('mode', PromotionRun::MODE_APPLY)
            ->whereIn('status', [PromotionRun::STATUS_SUCCEEDED, PromotionRun::STATUS_WARNING])
            ->latest('finished_at')
            ->latest('id')
            ->first();
        $latestCompare = $feedProfile->targetPromotionRuns()
            ->whereIn('mode', [PromotionRun::MODE_COMPARE, PromotionRun::MODE_DRY_RUN])
            ->latest('finished_at')
            ->latest('id')
            ->first()
            ?: $feedProfile->sourcePromotionRuns()
                ->whereIn('mode', [PromotionRun::MODE_COMPARE, PromotionRun::MODE_DRY_RUN])
                ->latest('finished_at')
                ->latest('id')
                ->first();
        $lastAppliedChecksum = $latestSourceApply?->sourceSnapshot?->checksum
            ?: $latestTargetApply?->sourceSnapshot?->checksum;
        $secretRebindPending = $feedProfile->sourceConnection?->promotionSecretRebindRequired() ?? false;
        $driftStatus = data_get($latestCompare?->summary, 'drift.status')
            ?: data_get($latestTargetApply?->summary, 'plan.drift.status');
        $needsPromotion = $lastAppliedChecksum !== null
            ? $lastAppliedChecksum !== $currentChecksum
            : ($driftStatus === 'drift_detected' ? true : null);

        return [
            'current_checksum' => $currentChecksum,
            'current_fingerprints' => (array) data_get($currentPayload, 'fingerprints', []),
            'latest_snapshot' => $latestSnapshot,
            'latest_compare' => $latestCompare,
            'latest_source_apply' => $latestSourceApply,
            'latest_target_apply' => $latestTargetApply,
            'drift_status' => $driftStatus ?: 'unknown',
            'promotion_needed' => $needsPromotion,
            'secret_rebind_pending' => $secretRebindPending,
            'secret_state' => $feedProfile->sourceConnection?->promotionSecretState(),
            'latest_secret_rebind' => $latestTargetApply?->summary['secret_rebind'] ?? $latestCompare?->summary['secret_rebind'] ?? null,
            'status' => $this->status($needsPromotion, $driftStatus, $secretRebindPending, $latestTargetApply, $latestSourceApply),
        ];
    }

    private function status(
        ?bool $needsPromotion,
        ?string $driftStatus,
        bool $secretRebindPending,
        ?PromotionRun $latestTargetApply,
        ?PromotionRun $latestSourceApply
    ): string {
        if ($secretRebindPending) {
            return 'secret_rebind_pending';
        }

        if ($driftStatus === 'incompatible') {
            return 'incompatible';
        }

        if ($needsPromotion === true || $driftStatus === 'drift_detected') {
            return 'promotion_needed';
        }

        if ($latestTargetApply instanceof PromotionRun || $latestSourceApply instanceof PromotionRun) {
            return 'in_sync';
        }

        return 'unknown';
    }
}
