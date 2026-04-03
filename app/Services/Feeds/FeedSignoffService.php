<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationSignoff;
use App\Models\FeedProfile;
use App\Models\User;
use RuntimeException;

class FeedSignoffService
{
    private const STATUS_RANK = [
        FeedGenerationSignoff::STATUS_PENDING_REVIEW => 10,
        FeedGenerationSignoff::STATUS_INTERNAL_APPROVED => 20,
        FeedGenerationSignoff::STATUS_CLIENT_REVIEW => 30,
        FeedGenerationSignoff::STATUS_CLIENT_APPROVED => 40,
        FeedGenerationSignoff::STATUS_REJECTED => 0,
        FeedGenerationSignoff::STATUS_SUPERSEDED => 0,
    ];

    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
        private readonly PilotNotificationService $notificationService,
    ) {}

    public function current(FeedGeneration $generation): ?FeedGenerationSignoff
    {
        return $generation->signoffs()
            ->where('is_current', true)
            ->latest('reviewed_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(FeedProfile $feedProfile, FeedGeneration $generation): array
    {
        $current = $this->current($generation);
        $required = $feedProfile->signoffRequired();
        $requiredStatus = $feedProfile->requiredSignoffStatus();
        $allowed = ! $required || ($current !== null && $this->meetsRequirement($current->status, $requiredStatus));
        $reasons = [];

        if ($required && $current === null) {
            $reasons[] = sprintf('Sign-off is required before publish (%s) but no sign-off is recorded yet.', $requiredStatus);
        }

        if ($required && $current !== null && ! $this->meetsRequirement($current->status, $requiredStatus)) {
            $reasons[] = sprintf(
                'Current sign-off status [%s] does not satisfy the required status [%s].',
                $current->status,
                $requiredStatus
            );
        }

        if ($current?->status === FeedGenerationSignoff::STATUS_REJECTED) {
            $reasons[] = 'Candidate sign-off is rejected and must be reviewed before publish.';
        }

        return [
            'required' => $required,
            'required_status' => $requiredStatus,
            'allowed' => $allowed,
            'current' => $current,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    public function record(
        FeedGeneration $generation,
        string $status,
        ?User $user = null,
        ?string $reviewerName = null,
        ?string $note = null,
        ?string $reason = null,
        array $meta = []
    ): FeedGenerationSignoff {
        if (! in_array($status, FeedGenerationSignoff::statuses(), true)) {
            throw new RuntimeException('Unsupported sign-off status.');
        }

        $generation->signoffs()->where('is_current', true)->update(['is_current' => false]);

        $signoff = FeedGenerationSignoff::create([
            'shop_id' => $generation->shop_id,
            'feed_profile_id' => $generation->feed_profile_id,
            'feed_generation_id' => $generation->id,
            'user_id' => $user?->id,
            'reviewer_name' => $reviewerName ?: $user?->name,
            'status' => $status,
            'is_current' => true,
            'note' => $note,
            'reason' => $reason,
            'reviewed_at' => now(),
            'meta' => $meta,
        ]);

        $generation->update([
            'meta' => array_merge($generation->meta ?? [], [
                'signoff' => [
                    'id' => $signoff->id,
                    'status' => $signoff->status,
                    'reviewer_name' => $signoff->reviewer_name,
                    'reviewed_at' => $signoff->reviewed_at?->toIso8601String(),
                    'note' => $signoff->note,
                    'reason' => $signoff->reason,
                ],
            ]),
        ]);

        $action = 'signoff_'.$status;

        $this->auditService->record(
            $generation->feedProfile,
            $generation,
            $action,
            $user,
            $reason ?: $note,
            [
                'signoff_id' => $signoff->id,
                'status' => $status,
                'reviewer_name' => $signoff->reviewer_name,
                'note' => $note,
            ]
        );

        if (in_array($status, [FeedGenerationSignoff::STATUS_PENDING_REVIEW, FeedGenerationSignoff::STATUS_CLIENT_REVIEW], true)) {
            $this->notificationService->notifyFeedProfileAdmins(
                $generation->feedProfile,
                'feed.signoff_requested',
                'Generation sign-off requested',
                'A candidate generation is waiting for review.',
                [
                    'generation_id' => $generation->id,
                    'signoff_status' => $status,
                    'signoff_id' => $signoff->id,
                ],
                'warning',
                $generation
            );
        }

        if (in_array($status, [FeedGenerationSignoff::STATUS_INTERNAL_APPROVED, FeedGenerationSignoff::STATUS_CLIENT_APPROVED], true)) {
            $this->notificationService->notifyFeedProfileAdmins(
                $generation->feedProfile,
                'feed.signoff_approved',
                'Generation sign-off approved',
                'The candidate generation received the required sign-off state.',
                [
                    'generation_id' => $generation->id,
                    'signoff_status' => $status,
                    'signoff_id' => $signoff->id,
                ],
                'info',
                $generation
            );
        }

        return $signoff->refresh();
    }

    public function supersedeCurrent(FeedGeneration $generation, ?User $user = null, ?string $reason = null): ?FeedGenerationSignoff
    {
        $current = $this->current($generation);

        if (! $current instanceof FeedGenerationSignoff) {
            return null;
        }

        if ($current->status === FeedGenerationSignoff::STATUS_SUPERSEDED) {
            return $current;
        }

        return $this->record(
            $generation,
            FeedGenerationSignoff::STATUS_SUPERSEDED,
            $user,
            $current->reviewer_name,
            $current->note,
            $reason,
            ['superseded_signoff_id' => $current->id]
        );
    }

    public function meetsRequirement(string $currentStatus, string $requiredStatus): bool
    {
        return (self::STATUS_RANK[$currentStatus] ?? -1) >= (self::STATUS_RANK[$requiredStatus] ?? PHP_INT_MAX);
    }
}
