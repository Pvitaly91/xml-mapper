<?php

namespace App\Services\Feeds;

use App\Models\FeedFirstPullVerification;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\FeedProfileCutover;
use App\Models\FeedbackRecord;
use App\Models\User;
use App\Services\Shops\ShopOnboardingService;
use Illuminate\Support\Carbon;
use RuntimeException;

class FeedCutoverService
{
    public function __construct(
        private readonly FeedReleaseReadinessService $readinessService,
        private readonly FeedSignoffService $signoffService,
        private readonly ShopOnboardingService $onboardingService,
        private readonly FeedReleaseAuditService $auditService,
    ) {
    }

    public function current(FeedProfile $feedProfile): ?FeedProfileCutover
    {
        return $feedProfile->currentCutover()->first();
    }

    public function begin(
        FeedProfile $feedProfile,
        ?FeedGeneration $generation = null,
        ?User $user = null,
        ?string $note = null,
        ?string $plannedStartsAt = null,
        ?string $plannedEndsAt = null
    ): FeedProfileCutover {
        $generation ??= $feedProfile->latestGeneration;

        if (! $generation instanceof FeedGeneration) {
            throw new RuntimeException('Build a generation before starting production cutover.');
        }

        $current = $this->current($feedProfile);

        if (! $current instanceof FeedProfileCutover || $current->target_generation_id !== $generation->id) {
            $feedProfile->cutovers()->where('is_current', true)->update(['is_current' => false]);

            $current = FeedProfileCutover::create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'target_generation_id' => $generation->id,
                'initiated_by_user_id' => $user?->id,
                'status' => FeedProfileCutover::STATUS_ONBOARDING_COMPLETE,
                'is_current' => true,
                'note' => $note,
                'planned_window_starts_at' => $this->parseTime($plannedStartsAt, $feedProfile),
                'planned_window_ends_at' => $this->parseTime($plannedEndsAt, $feedProfile),
            ]);
        } else {
            $current->forceFill([
                'note' => $note ?: $current->note,
                'planned_window_starts_at' => $this->parseTime($plannedStartsAt, $feedProfile) ?? $current->planned_window_starts_at,
                'planned_window_ends_at' => $this->parseTime($plannedEndsAt, $feedProfile) ?? $current->planned_window_ends_at,
            ])->save();
        }

        $this->auditService->record(
            $feedProfile,
            $generation,
            'cutover_started',
            $user,
            $note,
            [
                'cutover_id' => $current->id,
                'planned_window_starts_at' => $current->planned_window_starts_at?->toIso8601String(),
                'planned_window_ends_at' => $current->planned_window_ends_at?->toIso8601String(),
            ]
        );

        return $this->syncState($feedProfile->fresh(), $generation->fresh(), $user, $note) ?? $current->fresh();
    }

    public function syncState(
        FeedProfile $feedProfile,
        ?FeedGeneration $generation = null,
        ?User $user = null,
        ?string $reason = null
    ): ?FeedProfileCutover {
        $feedProfile->loadMissing(['shop.users', 'sourceConnection.latestImport', 'publishedGeneration', 'latestGeneration']);
        $generation ??= $feedProfile->latestGeneration;
        $current = $this->current($feedProfile);

        if (! $current instanceof FeedProfileCutover && ! $generation instanceof FeedGeneration) {
            return null;
        }

        if (! $current instanceof FeedProfileCutover) {
            $current = FeedProfileCutover::create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'target_generation_id' => $generation?->id,
                'initiated_by_user_id' => $user?->id,
                'status' => FeedProfileCutover::STATUS_CUTOVER_BLOCKED,
                'is_current' => true,
                'note' => $reason,
            ]);
        }

        $generation = $generation
            ?? $current->targetGeneration()->first()
            ?? $feedProfile->publishedGeneration
            ?? $feedProfile->latestGeneration;

        if (! $generation instanceof FeedGeneration) {
            return $current;
        }

        $signoff = $this->signoffService->evaluate($feedProfile, $generation);
        $releaseReadiness = $this->readinessService->evaluate($feedProfile, $generation);
        $publishedGeneration = $feedProfile->publishedGeneration;
        $latestVerification = $feedProfile->firstPullVerifications()->latest('verified_at')->first();
        $feedbackSummary = $this->feedbackSummary($feedProfile, $generation);
        $onboarding = $this->onboardingSummary($feedProfile);
        $status = $this->resolveStatus(
            $feedProfile,
            $generation,
            $current,
            $releaseReadiness,
            $signoff,
            $latestVerification,
            $feedbackSummary,
            $onboarding['completed']
        );
        $meta = array_merge($current->meta ?? [], [
            'blocking_issues' => $releaseReadiness['blocking_issues'],
            'warnings' => $releaseReadiness['warnings'],
            'next_steps' => $releaseReadiness['next_steps'],
            'signoff' => [
                'required' => $signoff['required'],
                'required_status' => $signoff['required_status'],
                'current_status' => $signoff['current']?->status,
                'allowed' => $signoff['allowed'],
                'reasons' => $signoff['reasons'],
            ],
            'feedback_summary' => $feedbackSummary,
            'onboarding' => $onboarding,
        ]);
        $previousStatus = $current->status;

        $current->forceFill([
            'target_generation_id' => $generation->id,
            'published_generation_id' => $publishedGeneration?->id,
            'status' => $status,
            'actual_published_at' => $publishedGeneration?->id === $generation->id
                ? ($generation->published_at ?? $current->actual_published_at)
                : $current->actual_published_at,
            'first_verified_at' => $latestVerification?->verified_at ?? $current->first_verified_at,
            'meta' => $meta,
        ])->save();

        if ($previousStatus !== $status) {
            $this->auditService->record(
                $feedProfile,
                $generation,
                'cutover_status_changed',
                $user,
                $reason,
                [
                    'cutover_id' => $current->id,
                    'from_status' => $previousStatus,
                    'to_status' => $status,
                ]
            );
        }

        return $current->fresh(['targetGeneration', 'publishedGeneration', 'initiatedBy']);
    }

    public function markPublished(
        FeedProfile $feedProfile,
        FeedGeneration $generation,
        ?User $user = null,
        ?string $reason = null
    ): ?FeedProfileCutover {
        $cutover = $this->syncState($feedProfile, $generation, $user, $reason);

        if (! $cutover instanceof FeedProfileCutover) {
            return null;
        }

        $cutover->forceFill([
            'published_generation_id' => $generation->id,
            'actual_published_at' => $generation->published_at ?? now(),
            'status' => FeedProfileCutover::STATUS_CUTOVER_PUBLISHED,
        ])->save();

        return $cutover->fresh();
    }

    public function markFirstPullVerified(
        FeedProfile $feedProfile,
        FeedGeneration $generation,
        FeedFirstPullVerification $verification,
        ?User $user = null,
        ?string $reason = null
    ): ?FeedProfileCutover {
        $cutover = $this->syncState($feedProfile, $generation, $user, $reason);

        if (! $cutover instanceof FeedProfileCutover) {
            return null;
        }

        $cutover->forceFill([
            'first_verified_at' => $verification->verified_at,
            'status' => $verification->status === FeedFirstPullVerification::STATUS_FAILED
                ? FeedProfileCutover::STATUS_CUTOVER_BLOCKED
                : FeedProfileCutover::STATUS_FIRST_PULL_VERIFIED,
        ])->save();

        return $this->syncState($feedProfile->fresh(), $generation->fresh(), $user, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile, ?FeedGeneration $generation = null): array
    {
        $cutover = $this->syncState($feedProfile, $generation);
        $generation ??= $cutover?->targetGeneration ?? $feedProfile->latestGeneration;
        $releaseReadiness = $generation instanceof FeedGeneration
            ? $this->readinessService->evaluate($feedProfile, $generation)
            : null;

        return [
            'cutover' => $cutover,
            'generation' => $generation,
            'blocking_issues' => $cutover->meta['blocking_issues'] ?? ($releaseReadiness['blocking_issues'] ?? []),
            'warnings' => $cutover->meta['warnings'] ?? ($releaseReadiness['warnings'] ?? []),
            'next_steps' => $cutover->meta['next_steps'] ?? ($releaseReadiness['next_steps'] ?? []),
            'feedback_summary' => $cutover->meta['feedback_summary'] ?? $this->feedbackSummary($feedProfile, $generation),
            'onboarding' => $cutover->meta['onboarding'] ?? $this->onboardingSummary($feedProfile),
        ];
    }

    /**
     * @param  array<string, mixed>  $releaseReadiness
     * @param  array<string, mixed>  $signoff
     * @param  array<string, int>  $feedbackSummary
     */
    private function resolveStatus(
        FeedProfile $feedProfile,
        FeedGeneration $generation,
        FeedProfileCutover $current,
        array $releaseReadiness,
        array $signoff,
        ?FeedFirstPullVerification $latestVerification,
        array $feedbackSummary,
        bool $onboardingCompleted
    ): string {
        if (! $onboardingCompleted) {
            return FeedProfileCutover::STATUS_CUTOVER_BLOCKED;
        }

        $sourceHealthy = $releaseReadiness['checks']['source_healthy']['ok'] ?? false;
        $syncFresh = $releaseReadiness['checks']['last_sync_fresh']['ok'] ?? false;
        $mappingsComplete = $releaseReadiness['checks']['mappings_complete']['ok'] ?? false;
        $candidateReady = in_array($generation->release_status, [
            FeedGeneration::RELEASE_STATUS_CANDIDATE,
            FeedGeneration::RELEASE_STATUS_APPROVED,
            FeedGeneration::RELEASE_STATUS_PUBLISHED,
        ], true);
        $signoffComplete = ($signoff['allowed'] ?? false)
            && in_array($generation->release_status, [
                FeedGeneration::RELEASE_STATUS_APPROVED,
                FeedGeneration::RELEASE_STATUS_PUBLISHED,
            ], true);
        $isPublished = $feedProfile->published_generation_id === $generation->id
            && $generation->release_status === FeedGeneration::RELEASE_STATUS_PUBLISHED;

        if ($isPublished && $latestVerification?->status === FeedFirstPullVerification::STATUS_FAILED) {
            return FeedProfileCutover::STATUS_CUTOVER_BLOCKED;
        }

        if ($isPublished && $latestVerification instanceof FeedFirstPullVerification) {
            if (($feedbackSummary['open_total'] ?? 0) > 0) {
                return FeedProfileCutover::STATUS_ACCEPTANCE_IN_PROGRESS;
            }

            if (($feedbackSummary['imports_total'] ?? 0) > 0) {
                return FeedProfileCutover::STATUS_PILOT_STABLE;
            }

            return FeedProfileCutover::STATUS_FIRST_PULL_VERIFIED;
        }

        if ($isPublished) {
            return FeedProfileCutover::STATUS_CUTOVER_PUBLISHED;
        }

        if (
            $signoffComplete
            && $releaseReadiness['blocking_issues'] === []
            && ($current->planned_window_starts_at !== null || $current->planned_window_ends_at !== null)
        ) {
            return FeedProfileCutover::STATUS_CUTOVER_SCHEDULED;
        }

        if ($signoffComplete) {
            return FeedProfileCutover::STATUS_SIGNOFF_COMPLETE;
        }

        if ($candidateReady) {
            return FeedProfileCutover::STATUS_CANDIDATE_READY;
        }

        if ($mappingsComplete) {
            return FeedProfileCutover::STATUS_MAPPINGS_RECONCILED;
        }

        if ($sourceHealthy && $syncFresh) {
            return FeedProfileCutover::STATUS_SYNC_VERIFIED;
        }

        return FeedProfileCutover::STATUS_ONBOARDING_COMPLETE;
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardingSummary(FeedProfile $feedProfile): array
    {
        $essentialCompleted = $feedProfile->shop !== null
            && $feedProfile->sourceConnection !== null
            && $feedProfile->exists;
        $admin = $feedProfile->shop?->users()->where('role', 'admin')->oldest('id')->first();

        if ($admin instanceof User) {
            $summary = $this->onboardingService->summarize($admin);
            $summary['completed'] = $essentialCompleted;

            return $summary;
        }

        return [
            'completed' => $essentialCompleted,
            'current_step' => $essentialCompleted ? 'release_center' : 'source_connection',
            'steps' => [],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function feedbackSummary(FeedProfile $feedProfile, ?FeedGeneration $generation = null): array
    {
        $query = FeedbackRecord::query()->where('feed_profile_id', $feedProfile->id);

        if ($generation instanceof FeedGeneration) {
            $query->where(function ($builder) use ($generation): void {
                $builder->where('feed_generation_id', $generation->id)
                    ->orWhereNull('feed_generation_id');
            });
        }

        return [
            'imports_total' => $feedProfile->feedbackImports()->count(),
            'accepted_total' => (clone $query)->where('status', FeedbackRecord::STATUS_ACCEPTED)->count(),
            'rejected_total' => (clone $query)->where('status', FeedbackRecord::STATUS_REJECTED)->count(),
            'warning_total' => (clone $query)->where('status', FeedbackRecord::STATUS_WARNING)->count(),
            'unmatched_total' => (clone $query)->whereNull('feed_item_id')->count(),
            'open_total' => (clone $query)->whereIn('resolution_status', [
                FeedbackRecord::RESOLUTION_OPEN,
                FeedbackRecord::RESOLUTION_IN_PROGRESS,
            ])->count(),
        ];
    }

    private function parseTime(?string $value, FeedProfile $feedProfile): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value, $feedProfile->publishWindowTimezone());
    }
}
