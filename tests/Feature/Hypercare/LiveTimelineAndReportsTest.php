<?php

namespace Tests\Feature\Hypercare;

use App\Services\Feeds\FeedHypercareReportService;
use App\Services\Feeds\FeedLiveTimelineService;
use App\Services\Feeds\FeedPreviewLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class LiveTimelineAndReportsTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPublishedHypercareContext;
    use RefreshDatabase;

    public function test_live_timeline_records_events_filters_by_type_and_downloads_csv(): void
    {
        ['feedProfile' => $feedProfile, 'generation' => $generation, 'admin' => $admin] = $this->seedPublishedHypercareContext();
        $this->actingAs($admin);

        app(FeedPreviewLinkService::class)->create($generation, 60, $admin, 'QA preview');
        $this->post(route('admin.feed-profiles.hypercare.note', $feedProfile), ['body' => 'Watching first live pull'])->assertSessionHasNoErrors();

        $timeline = app(FeedLiveTimelineService::class)->events($feedProfile);
        $smokeOnly = app(FeedLiveTimelineService::class)->events($feedProfile, ['event_type' => 'smoke_check']);

        $this->assertTrue($timeline->contains(fn (array $event) => $event['event_type'] === 'release_event'));
        $this->assertTrue($timeline->contains(fn (array $event) => $event['event_type'] === 'smoke_check'));
        $this->assertGreaterThanOrEqual(1, $smokeOnly->count());

        $this->get(route('admin.feed-profiles.hypercare.timeline.download', $feedProfile))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_digest_and_handoff_reports_are_generated_and_contain_expected_sections(): void
    {
        ['feedProfile' => $feedProfile] = $this->seedPublishedHypercareContext();

        $service = app(FeedHypercareReportService::class);
        $digest = $service->dailyDigest($feedProfile);
        $handoff = $service->shiftHandoff($feedProfile);

        $this->assertStringContainsString('Daily Hypercare Digest', $digest['content']);
        $this->assertStringContainsString('Shift Handoff', $handoff['content']);
        $this->assertStringContainsString('Open Incidents', $handoff['content']);
    }
}
