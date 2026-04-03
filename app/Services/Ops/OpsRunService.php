<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\Shop;
use App\Models\User;
use Throwable;

class OpsRunService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function start(
        string $type,
        ?Shop $shop = null,
        ?FeedProfile $feedProfile = null,
        ?User $user = null,
        array $meta = []
    ): OpsRun {
        return OpsRun::create([
            'shop_id' => $shop?->id ?? $feedProfile?->shop_id,
            'feed_profile_id' => $feedProfile?->id,
            'user_id' => $user?->id,
            'type' => $type,
            'status' => OpsRun::STATUS_RUNNING,
            'started_at' => now(),
            'meta' => $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $meta
     */
    public function finish(
        OpsRun $run,
        string $status = OpsRun::STATUS_SUCCEEDED,
        array $summary = [],
        array $meta = [],
        ?string $artifactPath = null,
        ?int $artifactSizeBytes = null,
        ?string $errorMessage = null
    ): OpsRun {
        $run->forceFill([
            'status' => $status,
            'summary' => $summary,
            'meta' => array_merge($run->meta ?? [], $meta),
            'artifact_path' => $artifactPath ?? $run->artifact_path,
            'artifact_size_bytes' => $artifactSizeBytes ?? $run->artifact_size_bytes,
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $meta
     */
    public function fail(
        OpsRun $run,
        Throwable|string $error,
        array $summary = [],
        array $meta = []
    ): OpsRun {
        return $this->finish(
            $run,
            OpsRun::STATUS_FAILED,
            $summary,
            $meta,
            errorMessage: $error instanceof Throwable ? $error->getMessage() : $error,
        );
    }
}
