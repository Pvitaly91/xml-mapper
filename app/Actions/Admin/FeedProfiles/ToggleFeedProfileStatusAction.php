<?php

namespace App\Actions\Admin\FeedProfiles;

use App\Models\FeedProfile;

class ToggleFeedProfileStatusAction
{
    public function handle(FeedProfile $feedProfile, string $status): FeedProfile
    {
        $feedProfile->update([
            'status' => $status,
            'next_build_at' => $status === FeedProfile::STATUS_ACTIVE
                ? now()->addMinutes($feedProfile->build_interval_minutes)
                : null,
        ]);

        return $feedProfile->refresh();
    }
}
