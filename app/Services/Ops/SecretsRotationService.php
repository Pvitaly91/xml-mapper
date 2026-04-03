<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\SourceConnection;
use App\Models\User;

class SecretsRotationService
{
    public const TARGET_PROM_API_TOKEN = 'prom_api_token';

    public const TARGET_APP_SECRET = 'app_secret';

    public const TARGET_DEPLOY_CREDENTIALS = 'deploy_credentials';

    public function __construct(
        private readonly OpsRunService $opsRunService,
    ) {}

    public function record(
        string $target,
        ?SourceConnection $sourceConnection = null,
        ?FeedProfile $feedProfile = null,
        ?User $user = null,
        ?string $note = null
    ): OpsRun {
        $shop = $sourceConnection?->shop ?? $feedProfile?->shop;
        $run = $this->opsRunService->start(OpsRun::TYPE_SECRET_ROTATION, $shop, $feedProfile, $user, [
            'target' => $target,
            'source_connection_id' => $sourceConnection?->id,
            'note' => $note,
        ]);

        return $this->opsRunService->finish($run, OpsRun::STATUS_SUCCEEDED, [
            'target' => $target,
        ], [
            'rotated_at' => now()->toIso8601String(),
            'source_connection_id' => $sourceConnection?->id,
            'note' => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(SourceConnection $sourceConnection): array
    {
        return [
            'token_present' => filled($sourceConnection->api_token),
            'token_last_validated_at' => $sourceConnection->last_connection_check_at,
            'connection_health' => $sourceConnection->last_connection_check_status ?: $sourceConnection->last_sync_status,
            'latest_rotation' => OpsRun::query()
                ->where('type', OpsRun::TYPE_SECRET_ROTATION)
                ->where('shop_id', $sourceConnection->shop_id)
                ->where('meta->source_connection_id', $sourceConnection->id)
                ->latest('started_at')
                ->first(),
            'history' => OpsRun::query()
                ->where('type', OpsRun::TYPE_SECRET_ROTATION)
                ->where('shop_id', $sourceConnection->shop_id)
                ->where('meta->source_connection_id', $sourceConnection->id)
                ->latest('started_at')
                ->limit(10)
                ->get(),
        ];
    }
}
