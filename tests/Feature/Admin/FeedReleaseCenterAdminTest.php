<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedReleaseCenterAdminTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_release_center_page_renders_correct_state(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.release-center', $feedProfile))
            ->assertOk()
            ->assertSee('Go-Live Checklist')
            ->assertSee((string) $generation->id)
            ->assertSee('Generation must be approved before publishing.');
    }

    public function test_generation_details_page_renders_actions_and_diagnostics(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.generations.show', [$feedProfile, $generation]))
            ->assertOk()
            ->assertSee('Actions')
            ->assertSee('Readiness')
            ->assertSee('Smoke Check')
            ->assertSee('Generation Diff');
    }

    public function test_manual_release_actions_are_recorded_with_user_and_reason(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'variant' => $variant] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.generations.candidate', [$feedProfile, $generation]), [
                'reason' => 'Candidate review complete',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.generations.approve', [$feedProfile, $generation]), [
                'reason' => 'Ready for release',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.publish', $feedProfile), [
                'generation_id' => $generation->id,
            ])
            ->assertRedirect();

        $secondGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());

        $this->actingAs($admin)
            ->withSession([
                'admin_auth.password_confirmed_at' => now()->toIso8601String(),
            ])
            ->post(route('admin.feed-profiles.publish', $feedProfile), [
                'generation_id' => $secondGeneration->id,
                'force_publish' => '1',
                'reason' => 'Emergency override',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.generations.smoke-check', [$feedProfile, $generation]), [
                'reason' => 'Operator verification',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->withSession([
                'admin_auth.password_confirmed_at' => now()->toIso8601String(),
            ])
            ->post(route('admin.feed-profiles.rollback', $feedProfile), [
                'to_generation_id' => $generation->id,
                'reason' => 'Restore approved release',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'candidate_marked',
            'user_id' => $admin->id,
            'reason' => 'Candidate review complete',
        ]);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'approved',
            'user_id' => $admin->id,
            'reason' => 'Ready for release',
        ]);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $secondGeneration->id,
            'action' => 'guardrails_overridden',
            'user_id' => $admin->id,
            'reason' => 'Emergency override',
        ]);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'smoke_check_rerun',
            'user_id' => $admin->id,
            'reason' => 'Operator verification',
        ]);
        $this->assertDatabaseHas('feed_release_events', [
            'feed_generation_id' => $generation->id,
            'action' => 'rolled_back',
            'user_id' => $admin->id,
            'reason' => 'Restore approved release',
        ]);
    }
}
