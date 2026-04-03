<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedbackRecord;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedbackImportWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_csv_dry_run_and_json_import_persist_feedback(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin);
        $releaseService->approve($generation->fresh(), $admin);
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $csv = implode("\n", [
            'offer_id,status,rejection_reason_code,rejection_reason_message',
            'ofr_test_001,rejected,IMG_01,Image issue',
            'unknown-offer,warning,MISC_01,Unknown item',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.feed-profiles.feedback.preview', $feedProfile), [
                'format' => 'csv',
                'file' => UploadedFile::fake()->createWithContent('feedback.csv', $csv),
                'generation_id' => $generation->id,
            ])
            ->assertRedirect(route('admin.feed-profiles.feedback.create', $feedProfile));

        $preview = $response->getSession()->get('feedback_preview');

        $this->assertSame(1, $preview['summary']['matched']);
        $this->assertSame(1, $preview['summary']['unmatched']);

        $json = json_encode([
            ['offer_id' => 'ofr_test_001', 'status' => 'accepted'],
            ['offer_id' => 'missing-offer', 'status' => 'rejected', 'reason_code' => 'MAP_01', 'reason_message' => 'Missing mapping'],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($admin)
            ->post(route('admin.feed-profiles.feedback.store', $feedProfile), [
                'format' => 'json',
                'file' => UploadedFile::fake()->createWithContent('feedback.json', $json),
                'generation_id' => $generation->id,
            ])
            ->assertRedirect(route('admin.feed-profiles.feedback-workbench.index', $feedProfile));

        $this->assertDatabaseHas('feedback_imports', [
            'feed_profile_id' => $feedProfile->id,
            'matched_total' => 1,
            'unmatched_total' => 1,
        ]);
        $this->assertDatabaseHas('feedback_records', [
            'feed_profile_id' => $feedProfile->id,
            'status' => FeedbackRecord::STATUS_ACCEPTED,
            'offer_id' => 'ofr_test_001',
        ]);
        $this->assertDatabaseHas('feedback_records', [
            'feed_profile_id' => $feedProfile->id,
            'status' => FeedbackRecord::STATUS_REJECTED,
            'offer_id' => 'missing-offer',
        ]);
    }
}
