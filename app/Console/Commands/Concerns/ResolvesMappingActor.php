<?php

namespace App\Console\Commands\Concerns;

use App\Models\FeedProfile;
use App\Models\MappingBatch;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use RuntimeException;

trait ResolvesMappingActor
{
    protected function resolveActorForFeedProfile(FeedProfile $feedProfile, ?string $identifier = null): User
    {
        if (is_string($identifier) && trim($identifier) !== '') {
            return $this->resolveUserIdentifier($identifier);
        }

        if ($feedProfile->user instanceof User) {
            return $feedProfile->user;
        }

        $actor = app(AdminAccessService::class)->activeAdminUsers($feedProfile->shop)->first();

        if (! $actor instanceof User) {
            throw new RuntimeException('No active admin user is available for this feed profile.');
        }

        return $actor;
    }

    protected function resolveActorForBatch(MappingBatch $batch, ?string $identifier = null): User
    {
        if (is_string($identifier) && trim($identifier) !== '') {
            return $this->resolveUserIdentifier($identifier);
        }

        if ($batch->requestedBy instanceof User) {
            return $batch->requestedBy;
        }

        return $this->resolveActorForFeedProfile($batch->feedProfile()->firstOrFail());
    }
}
