<?php

namespace App\Services\Feeds;

use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\User;
use RuntimeException;
use Throwable;

class FeedReleaseService
{
    public function __construct(
        private readonly FeedPublishServiceInterface $feedPublishService,
        private readonly FeedReleaseReadinessService $readinessService,
        private readonly FeedReleaseAuditService $auditService,
        private readonly PilotNotificationService $notificationService,
        private readonly FeedSmokeCheckService $smokeCheckService,
    ) {
    }

    public function markCandidate(FeedGeneration $generation, ?User $user = null, ?string $reason = null): FeedGeneration
    {
        if (! in_array($generation->status, [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED], true)) {
            throw new RuntimeException('Only built generations can become release candidates.');
        }

        $generation->update([
            'release_status' => FeedGeneration::RELEASE_STATUS_CANDIDATE,
            'release_candidate_at' => now(),
        ]);

        $this->auditService->record($generation->feedProfile, $generation, 'candidate_marked', $user, $reason);

        return $generation->refresh();
    }

    public function approve(FeedGeneration $generation, ?User $user = null, ?string $reason = null): FeedGeneration
    {
        if (! in_array($generation->status, [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED], true)) {
            throw new RuntimeException('Only built generations can be approved.');
        }

        $generation->update([
            'release_status' => FeedGeneration::RELEASE_STATUS_APPROVED,
            'release_candidate_at' => $generation->release_candidate_at ?? now(),
            'approved_at' => now(),
            'approved_by_user_id' => $user?->id,
        ]);

        $this->auditService->record($generation->feedProfile, $generation, 'approved', $user, $reason);

        return $generation->refresh();
    }

    public function publish(
        FeedProfile $feedProfile,
        ?FeedGeneration $generation = null,
        bool $force = false,
        ?string $reason = null,
        ?User $user = null
    ): FeedGeneration {
        $generation ??= $feedProfile->generations()
            ->whereIn('status', [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED])
            ->latest('id')
            ->first();

        if (! $generation instanceof FeedGeneration) {
            throw new RuntimeException('No built generation is available for release.');
        }

        if ($force && blank($reason)) {
            throw new RuntimeException('Force publish requires a reason.');
        }

        $readiness = $this->readinessService->evaluate($feedProfile, $generation);

        if (! $force && $readiness['blocking_issues'] !== []) {
            $this->auditService->record($feedProfile, $generation, 'publish_blocked', $user, $reason, [
                'blocking_issues' => $readiness['blocking_issues'],
                'warnings' => $readiness['warnings'],
            ]);
            $this->notificationService->notifyFeedProfileAdmins(
                $feedProfile,
                'feed.publish_blocked',
                'Feed publish blocked',
                'Publishing was blocked by release readiness checks.',
                [
                    'generation_id' => $generation->id,
                    'blocking_issues' => $readiness['blocking_issues'],
                ],
                'warning',
                $generation
            );

            throw new RuntimeException('Publish blocked: '.implode(' ', $readiness['blocking_issues']));
        }

        if ($force) {
            $this->auditService->record($feedProfile, $generation, 'guardrails_overridden', $user, $reason, [
                'blocking_issues' => $readiness['blocking_issues'],
                'warnings' => $readiness['warnings'],
            ]);
        }

        try {
            $published = $this->feedPublishService->publish($feedProfile, $generation, $force);
        } catch (Throwable $exception) {
            $generation->update(['release_status' => FeedGeneration::RELEASE_STATUS_PUBLISH_FAILED]);
            $this->auditService->record($feedProfile, $generation, 'publish_failed', $user, $reason, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->notificationService->notifyFeedProfileAdmins(
                $feedProfile,
                'feed.publish_failed',
                'Feed publish failed',
                $exception->getMessage(),
                [
                    'generation_id' => $generation->id,
                    'force' => $force,
                ],
                'error',
                $generation
            );

            throw $exception;
        }

        $action = $force ? 'force_published' : 'published';
        $this->auditService->record($feedProfile, $published, $action, $user, $reason, [
            'force' => $force,
            'readiness_warnings' => $readiness['warnings'],
        ]);

        $this->smokeCheckService->run($feedProfile->fresh(), $published->fresh(), FeedGenerationSmokeCheck::TRIGGER_AUTOMATIC);

        return $published->fresh();
    }

    public function rollback(
        FeedProfile $feedProfile,
        ?FeedGeneration $targetGeneration = null,
        string $reason,
        ?User $user = null
    ): FeedGeneration {
        if (blank($reason)) {
            throw new RuntimeException('Rollback requires a reason.');
        }

        $currentPublished = $feedProfile->publishedGeneration;

        if (! $currentPublished instanceof FeedGeneration) {
            throw new RuntimeException('No published generation is available to roll back.');
        }

        $targetGeneration ??= $feedProfile->generations()
            ->whereKeyNot($currentPublished->id)
            ->whereNotNull('file_path')
            ->latest('published_at')
            ->latest('id')
            ->first();

        if (! $targetGeneration instanceof FeedGeneration) {
            throw new RuntimeException('No rollback target generation is available.');
        }

        if ($targetGeneration->id === $currentPublished->id) {
            throw new RuntimeException('Rollback target must differ from the current published generation.');
        }

        $rolledBack = $this->feedPublishService->publish($feedProfile, $targetGeneration, true);

        if ($currentPublished->id !== $rolledBack->id) {
            $currentPublished->update([
                'release_status' => FeedGeneration::RELEASE_STATUS_ROLLED_BACK,
            ]);
        }

        $rolledBack->update([
            'release_status' => FeedGeneration::RELEASE_STATUS_PUBLISHED,
        ]);

        $this->auditService->record($feedProfile, $rolledBack, 'rolled_back', $user, $reason, [
            'from_generation_id' => $currentPublished->id,
            'to_generation_id' => $rolledBack->id,
        ]);
        $this->notificationService->notifyFeedProfileAdmins(
            $feedProfile,
            'feed.rollback_executed',
            'Feed rollback executed',
            'The published feed has been rolled back to a previous generation.',
            [
                'from_generation_id' => $currentPublished->id,
                'to_generation_id' => $rolledBack->id,
                'reason' => $reason,
            ],
            'warning',
            $rolledBack
        );

        $this->smokeCheckService->run($feedProfile->fresh(), $rolledBack->fresh(), FeedGenerationSmokeCheck::TRIGGER_AUTOMATIC);

        return $rolledBack->fresh();
    }
}
