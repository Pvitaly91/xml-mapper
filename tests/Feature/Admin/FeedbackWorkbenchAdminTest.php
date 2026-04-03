<?php

namespace Tests\Feature\Admin;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Models\FeedbackImport;
use App\Models\FeedbackRecord;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class FeedbackWorkbenchAdminTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_grouped_reasons_render_and_resolution_update_works(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile, 'product' => $product, 'variant' => $variant] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin);
        $releaseService->approve($generation->fresh(), $admin);
        $releaseService->publish($feedProfile->fresh(), $generation->fresh());

        $import = FeedbackImport::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'format' => 'csv',
            'status' => 'imported',
            'matched_total' => 1,
            'rejected_total' => 1,
            'warnings_total' => 0,
            'imported_at' => now(),
        ]);

        $record = FeedbackRecord::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feedback_import_id' => $import->id,
            'feed_generation_id' => $generation->id,
            'feed_item_id' => $variant->feedItems()->where('feed_profile_id', $feedProfile->id)->first()?->id,
            'source_product_id' => $product->id,
            'source_variant_id' => $variant->id,
            'status' => FeedbackRecord::STATUS_REJECTED,
            'resolution_status' => FeedbackRecord::RESOLUTION_OPEN,
            'offer_id' => 'ofr_test_001',
            'rejection_reason_code' => 'MAP_01',
            'rejection_reason_message' => 'Missing mapping',
            'imported_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.feed-profiles.feedback-workbench.index', $feedProfile))
            ->assertOk()
            ->assertSee('Grouped Reasons')
            ->assertSee('MAP_01')
            ->assertSee('Missing mapping');

        $this->actingAs($admin)
            ->put(route('admin.feed-profiles.feedback-records.update', [$feedProfile, $record]), [
                'resolution_status' => FeedbackRecord::RESOLUTION_FIXED,
                'resolution_note' => 'Resolved in mappings',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('feedback_records', [
            'id' => $record->id,
            'resolution_status' => FeedbackRecord::RESOLUTION_FIXED,
            'resolution_note' => 'Resolved in mappings',
        ]);
    }
}
