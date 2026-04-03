<?php

namespace App\Services\Feeds;

use App\Models\FeedFirstPullVerification;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Models\User;
use RuntimeException;

class FeedFirstPullVerificationService
{
    public function __construct(
        private readonly FeedSmokeCheckService $smokeCheckService,
        private readonly FeedCutoverService $cutoverService,
        private readonly FeedReleaseAuditService $auditService,
        private readonly PilotNotificationService $notificationService,
    ) {}

    public function run(
        FeedProfile $feedProfile,
        ?FeedGeneration $generation = null,
        string $trigger = FeedGenerationSmokeCheck::TRIGGER_MANUAL,
        ?User $user = null,
        ?string $reason = null
    ): FeedFirstPullVerification {
        $feedProfile->loadMissing('publishedGeneration');
        $generation ??= $feedProfile->publishedGeneration;

        if (! $generation instanceof FeedGeneration) {
            throw new RuntimeException('Publish a generation before running first-pull verification.');
        }

        if ($feedProfile->published_generation_id !== $generation->id) {
            throw new RuntimeException('First-pull verification is available only for the currently published generation.');
        }

        $smokeCheck = $this->smokeCheckService->run(
            $feedProfile,
            $generation,
            $trigger,
            $user,
            $reason ? 'First-pull verification: '.$reason : 'First-pull verification'
        );

        return $this->recordFromSmokeCheck($feedProfile, $generation, $smokeCheck, $trigger, $user, $reason);
    }

    public function recordFromSmokeCheck(
        FeedProfile $feedProfile,
        FeedGeneration $generation,
        FeedGenerationSmokeCheck $smokeCheck,
        string $trigger = FeedGenerationSmokeCheck::TRIGGER_AUTOMATIC,
        ?User $user = null,
        ?string $reason = null
    ): FeedFirstPullVerification {
        $verification = FeedFirstPullVerification::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'feed_profile_cutover_id' => $feedProfile->currentCutover?->id,
            'feed_generation_smoke_check_id' => $smokeCheck->id,
            'user_id' => $user?->id,
            'status' => $this->statusFromSmokeCheck($smokeCheck),
            'latency_ms' => $smokeCheck->latency_ms,
            'response_size_bytes' => $smokeCheck->response_size_bytes,
            'offers_total' => $smokeCheck->offers_total,
            'categories_total' => $smokeCheck->categories_total,
            'response_checksum' => $smokeCheck->response_checksum,
            'expected_checksum' => $smokeCheck->expected_checksum,
            'warnings' => $smokeCheck->warnings,
            'errors' => $smokeCheck->errors,
            'verified_at' => now(),
            'meta' => [
                'trigger_source' => $trigger,
                'expected' => [
                    'offers_total' => (int) ($generation->meta['summary']['ready'] ?? $generation->valid_items_total),
                    'categories_total' => (int) ($generation->meta['smoke_check']['categories_total'] ?? 0),
                    'checksum' => $generation->checksum,
                ],
                'reason' => $reason,
            ],
        ]);

        $generation->update([
            'meta' => array_merge($generation->meta ?? [], [
                'first_pull_verification' => [
                    'id' => $verification->id,
                    'status' => $verification->status,
                    'verified_at' => $verification->verified_at?->toIso8601String(),
                    'latency_ms' => $verification->latency_ms,
                    'offers_total' => $verification->offers_total,
                    'categories_total' => $verification->categories_total,
                    'errors' => $verification->errors,
                    'warnings' => $verification->warnings,
                ],
            ]),
        ]);

        $this->auditService->record(
            $feedProfile,
            $generation,
            $verification->status === FeedFirstPullVerification::STATUS_FAILED ? 'first_pull_verification_failed' : 'first_pull_verified',
            $user,
            $reason,
            [
                'verification_id' => $verification->id,
                'status' => $verification->status,
                'smoke_check_id' => $smokeCheck->id,
            ]
        );

        if ($verification->status === FeedFirstPullVerification::STATUS_FAILED) {
            $this->notificationService->notifyFeedProfileAdmins(
                $feedProfile,
                'feed.first_pull_verification_failed',
                'First production pull verification failed',
                'The first production pull verification failed after publish.',
                [
                    'generation_id' => $generation->id,
                    'verification_id' => $verification->id,
                    'errors' => $verification->errors,
                ],
                'error',
                $generation
            );
        }

        $this->cutoverService->markFirstPullVerified($feedProfile->fresh(), $generation->fresh(), $verification, $user, $reason);

        return $verification->fresh(['smokeCheck', 'cutover']);
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        $latest = $feedProfile->firstPullVerifications()->latest('verified_at')->first();

        return [
            'latest' => $latest,
            'has_run' => $latest instanceof FeedFirstPullVerification,
            'needs_rerun' => $latest instanceof FeedFirstPullVerification
                && $latest->verified_at?->lt(now()->subMinutes((int) config('feed_mediator.first_pull_verification.warning_reverify_after_minutes', 30))),
            'history' => $feedProfile->firstPullVerifications()
                ->latest('verified_at')
                ->limit(10)
                ->get(),
        ];
    }

    private function statusFromSmokeCheck(FeedGenerationSmokeCheck $smokeCheck): string
    {
        return match ($smokeCheck->status) {
            FeedGenerationSmokeCheck::STATUS_OK => FeedFirstPullVerification::STATUS_OK,
            FeedGenerationSmokeCheck::STATUS_WARNING => FeedFirstPullVerification::STATUS_WARNING,
            default => FeedFirstPullVerification::STATUS_FAILED,
        };
    }
}
