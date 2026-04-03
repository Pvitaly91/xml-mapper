<?php

namespace Tests\Concerns;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\SourceConnection;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Support\Facades\Storage;

trait CreatesPublishedHypercareContext
{
    /**
     * @return array<string, mixed>
     */
    protected function seedPublishedHypercareContext(bool $promApi = false): array
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['shop' => $shop, 'admin' => $admin, 'connection' => $connection, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();

        if ($promApi) {
            $connection->update([
                'driver' => SourceConnection::DRIVER_PROM_API,
                'api_base_url' => SourceConnection::defaultPromApiBaseUrl(),
                'api_version' => SourceConnection::defaultPromApiVersion(),
                'api_token' => 'test-api-token',
            ]);
        }

        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin);
        $releaseService->approve($generation->fresh(), $admin);
        $published = $releaseService->publish($feedProfile->fresh(), $generation->fresh(), false, null, $admin);

        return [
            'shop' => $shop,
            'admin' => $admin,
            'connection' => $connection->fresh(),
            'feedProfile' => $feedProfile->fresh(['currentHypercareWindow', 'publishedGeneration', 'latestGeneration']),
            'generation' => $published->fresh(),
            'hypercare' => $feedProfile->fresh()->currentHypercareWindow,
        ];
    }
}
