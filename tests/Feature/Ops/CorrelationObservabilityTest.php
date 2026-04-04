<?php

namespace Tests\Feature\Ops;

use App\Jobs\BuildFeedJob;
use App\Models\OpsAlert;
use App\Models\SyncLog;
use App\Services\Feeds\FeedBuildService;
use App\Services\Feeds\FeedFirstPullVerificationService;
use App\Services\Ops\CorrelationContext;
use App\Services\Ops\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class CorrelationObservabilityTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPublishedHypercareContext;
    use RefreshDatabase;

    public function test_correlation_id_propagates_into_alerts_deliveries_jobs_and_loggable_entities(): void
    {
        config()->set('feed_mediator.notifications.defaults.log_enabled', false);
        config()->set('feed_mediator.notifications.defaults.mail_enabled', false);

        ['feedProfile' => $feedProfile, 'generation' => $generation] = $this->seedPublishedHypercareContext();

        app(CorrelationContext::class)->activate('corr-ops-123');

        $job = new BuildFeedJob($feedProfile->id);
        $this->assertSame('corr-ops-123', $job->correlationId);

        $buildGeneration = app(FeedBuildService::class)->build($feedProfile);
        $buildLog = SyncLog::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('event', 'feed.built')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('corr-ops-123', data_get($buildLog->context, 'correlation_id'));

        $alert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_FIRST_PULL_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'First pull failed',
            'Marketplace did not pick up the feed.',
            [],
            $generation,
            $feedProfile->currentHypercareWindow,
            'corr-alert'
        );

        $this->assertSame('corr-ops-123', $alert->correlation_id);
        $this->assertDatabaseHas('ops_notification_deliveries', [
            'ops_alert_id' => $alert->id,
            'correlation_id' => 'corr-ops-123',
        ]);

        $verification = app(FeedFirstPullVerificationService::class)->recordFromSmokeCheck(
            $feedProfile,
            $generation,
            $generation->smokeChecks()->latest('id')->firstOrFail()
        );

        $this->assertSame('corr-ops-123', data_get($verification->meta, 'correlation_id'));
        $this->assertSame('corr-ops-123', data_get(
            $feedProfile->releaseEvents()->latest('id')->firstOrFail()->meta,
            'correlation_id'
        ));
        $this->assertNotNull($buildGeneration->id);
    }
}
