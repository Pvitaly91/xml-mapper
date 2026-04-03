<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Services\Feeds\FeedPreviewLinkService;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class AdminSecurityHardeningTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_admin_dashboard_returns_secure_headers(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_login_is_rate_limited(): void
    {
        config()->set('feed_mediator.security.rate_limits.admin_login_per_minute', 2);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->post(route('admin.login.store'), [
                'email' => 'wrong@example.com',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('admin.login.store'), [
            'email' => 'wrong@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_public_and_preview_xml_endpoints_are_not_broken_by_secure_headers(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation);
        $releaseService->approve($generation->fresh());
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $previewLink = app(FeedPreviewLinkService::class)->create($generation->fresh(), 60);
        $previewUrl = app(FeedPreviewLinkService::class)->urlFor($previewLink);

        $this->get(route('feeds.public', $feedProfile->public_token))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $this->get($previewUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }
}
