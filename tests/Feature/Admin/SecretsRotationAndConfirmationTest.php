<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\OpsRun;
use App\Models\SourceConnection;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Ops\SecretsRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class SecretsRotationAndConfirmationTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_secret_rotation_metadata_is_recorded(): void
    {
        ['admin' => $admin, 'connection' => $connection] = $this->seedBuildableCatalog();
        $connection->update([
            'driver' => SourceConnection::DRIVER_PROM_API,
            'api_token' => 'secret-token-value',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.source-connections.rotation', $connection), [
                'target' => SecretsRotationService::TARGET_PROM_API_TOKEN,
                'note' => 'Rotated during staging rehearsal',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ops_runs', [
            'type' => OpsRun::TYPE_SECRET_ROTATION,
            'shop_id' => $connection->shop_id,
            'status' => OpsRun::STATUS_SUCCEEDED,
        ]);
    }

    public function test_force_publish_requires_confirmation_when_hardening_is_enabled(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        config()->set('feed_mediator.security.require_high_risk_confirmation', true);

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        app(FeedReleaseService::class)->markCandidate($generation, $admin);

        $this->actingAs($admin)
            ->from(route('admin.feed-profiles.acceptance.show', $feedProfile))
            ->post(route('admin.feed-profiles.publish', $feedProfile), [
                'generation_id' => $generation->id,
                'force_publish' => 1,
                'reason' => 'Emergency override for test',
            ])
            ->assertRedirect(route('admin.feed-profiles.acceptance.show', $feedProfile))
            ->assertSessionHasErrors('confirmation');
    }
}
