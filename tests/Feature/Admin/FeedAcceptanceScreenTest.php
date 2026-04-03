<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Services\Feeds\FeedPreviewLinkService;
use App\Services\Feeds\FeedReleaseNotesService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedSignoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedAcceptanceScreenTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_acceptance_screen_renders_readiness_and_actions(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin, 'Ready for review');
        app(FeedSignoffService::class)->record($generation->fresh(), 'pending_review', $admin, 'QA operator', 'Waiting for internal approval');
        app(FeedReleaseNotesService::class)->add($generation->fresh(), 'External review note.', 'external', true, $admin);
        app(FeedPreviewLinkService::class)->create($generation->fresh(), 60, $admin, 'Share with merchant');

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.acceptance.show', ['feed_profile' => $feedProfile, 'generation_id' => $generation->id]))
            ->assertOk()
            ->assertSee('Go-Live Checklist')
            ->assertSee('Generate candidate preview link')
            ->assertSee('Download QA bundle')
            ->assertSee('Sign-off status')
            ->assertSee('pending_review')
            ->assertSee('Preview Links');
    }
}
