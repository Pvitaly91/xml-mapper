<?php

namespace Tests\Feature\Hypercare;

use App\Models\FeedbackImport;
use App\Models\FeedbackRecord;
use App\Services\Feeds\FeedbackSlaService;
use App\Services\Ops\HypercarePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class FeedbackSlaAndDigestTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPublishedHypercareContext;
    use RefreshDatabase;

    public function test_feedback_sla_metrics_aggregate_correctly_and_backlog_is_visible(): void
    {
        ['feedProfile' => $feedProfile, 'generation' => $generation, 'admin' => $admin, 'hypercare' => $hypercare] = $this->seedPublishedHypercareContext();

        $import = FeedbackImport::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'user_id' => $admin->id,
            'format' => 'csv',
            'status' => FeedbackImport::STATUS_IMPORTED,
            'imported_at' => now()->subHours(2),
        ]);

        FeedbackRecord::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feedback_import_id' => $import->id,
            'feed_generation_id' => $generation->id,
            'status' => FeedbackRecord::STATUS_REJECTED,
            'resolution_status' => FeedbackRecord::RESOLUTION_FIXED,
            'offer_id' => 'sku-1',
            'rejection_reason_code' => 'IMG',
            'rejection_reason_message' => 'Bad image',
            'imported_at' => now()->subMinutes(55),
            'acknowledged_at' => now()->subMinutes(40),
            'resolved_at' => now()->subMinutes(15),
            'acknowledged_by_user_id' => $admin->id,
            'resolution_user_id' => $admin->id,
        ]);

        FeedbackRecord::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feedback_import_id' => $import->id,
            'feed_generation_id' => $generation->id,
            'status' => FeedbackRecord::STATUS_REJECTED,
            'resolution_status' => FeedbackRecord::RESOLUTION_OPEN,
            'offer_id' => 'sku-2',
            'rejection_reason_code' => 'MAP',
            'rejection_reason_message' => 'Missing mapping',
            'imported_at' => now()->subMinutes(20),
        ]);

        $summary = app(FeedbackSlaService::class)->summarize($feedProfile);

        $this->assertSame(1, $summary['fixed']);
        $this->assertSame(1, $summary['open_rejected_items']);
        $this->assertSame(1, $summary['pending_backlog']);
        $this->assertGreaterThan(0, $summary['average_time_to_acknowledge_minutes']);
        $this->assertGreaterThan(0, $summary['average_time_to_resolve_minutes']);
    }

    public function test_rejection_spike_policy_raises_alert_and_backlog_is_surfaced(): void
    {
        ['feedProfile' => $feedProfile, 'generation' => $generation, 'admin' => $admin, 'hypercare' => $hypercare] = $this->seedPublishedHypercareContext();

        $import = FeedbackImport::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'user_id' => $admin->id,
            'format' => 'csv',
            'status' => FeedbackImport::STATUS_IMPORTED,
            'imported_at' => now()->subMinutes(20),
        ]);

        foreach (range(1, 12) as $index) {
            FeedbackRecord::create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'feedback_import_id' => $import->id,
                'feed_generation_id' => $generation->id,
                'status' => FeedbackRecord::STATUS_REJECTED,
                'resolution_status' => FeedbackRecord::RESOLUTION_OPEN,
                'offer_id' => 'sku-'.$index,
                'rejection_reason_code' => 'MAP',
                'rejection_reason_message' => 'Missing mapping',
                'imported_at' => now()->subMinutes(10),
            ]);
        }

        $result = app(HypercarePolicyService::class)->review($feedProfile->fresh(), $hypercare->fresh());

        $this->assertContains('critical', array_column($result['results'], 'status'));
        $this->assertDatabaseHas('ops_alerts', [
            'feed_profile_id' => $feedProfile->id,
            'fingerprint' => 'policy:feedback_rejection_spike',
        ]);
    }
}
