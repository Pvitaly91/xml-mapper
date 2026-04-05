<?php

namespace App\Actions\Admin\FeedItems;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Mappings\Automation\FeedItemMappingExceptionService;

class SaveFeedItemContentOverrideAction
{
    public function __construct(
        private readonly FeedItemMappingExceptionService $exceptionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(FeedProfile $feedProfile, FeedItem $feedItem, array $payload, ?User $actor = null): void
    {
        $this->exceptionService->syncContentOverrides(
            $feedProfile,
            $feedItem,
            $payload,
            (string) ($payload['reason'] ?? 'Manual content override'),
            $actor,
            'manual'
        );
    }
}
