<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsNotificationDelivery;
use App\Services\Ops\NotificationCenterService;
use App\Services\Ops\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class NotificationRoutingAndCenterTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPublishedHypercareContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('feed_mediator.notifications.defaults.database_enabled', false);
        config()->set('feed_mediator.notifications.defaults.log_enabled', false);
        config()->set('feed_mediator.notifications.defaults.mail_enabled', false);
    }

    public function test_muted_route_suppresses_delivery_and_history_keeps_it_visible(): void
    {
        ['feedProfile' => $feedProfile, 'admin' => $admin, 'generation' => $generation] = $this->seedPublishedHypercareContext();
        $service = app(NotificationCenterService::class);

        $service->saveRoute($feedProfile->shop, [
            'name' => 'Muted smoke route',
            'scope' => 'feed_profile',
            'feed_profile_id' => $feedProfile->id,
            'channel' => 'log',
            'event_family' => 'smoke_failed',
            'minimum_severity' => 'warning',
            'muted_until' => now()->addHour()->toIso8601String(),
            'target_value' => 'stack',
        ], null, $admin);

        $alert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_SMOKE_CHECK_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'Smoke failed',
            'Feed is invalid.',
            [],
            $generation,
            $feedProfile->currentHypercareWindow,
            'smoke-muted'
        );

        $delivery = OpsNotificationDelivery::query()->where('ops_alert_id', $alert->id)->firstOrFail();

        $this->assertSame(OpsNotificationDelivery::STATUS_SUPPRESSED, $delivery->status);
        $this->assertSame(OpsAlert::NOTIFICATION_SUPPRESSED, $alert->fresh()->notification_state);
    }

    public function test_escalation_updates_alert_notification_state_and_admin_center_filters_render(): void
    {
        ['feedProfile' => $feedProfile, 'admin' => $admin, 'generation' => $generation] = $this->seedPublishedHypercareContext();
        $service = app(NotificationCenterService::class);
        $this->actingAs($admin);

        $service->saveRoute($feedProfile->shop, [
            'name' => 'Escalation log route',
            'scope' => 'feed_profile',
            'feed_profile_id' => $feedProfile->id,
            'channel' => 'log',
            'event_family' => 'publish_failed',
            'minimum_severity' => 'warning',
            'target_value' => 'stack',
        ], null, $admin);

        $alert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_PUBLISH_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'Publish failed',
            'Publish exploded.',
            [],
            $generation,
            $feedProfile->currentHypercareWindow,
            'publish-escalate'
        );

        $alert->update(['last_raised_at' => now()->subMinutes(30)]);
        app(OpsAlertService::class)->escalateDue(null, $feedProfile);

        $this->assertSame(OpsAlert::STATE_ESCALATED, $alert->fresh()->state);
        $this->assertSame(OpsAlert::NOTIFICATION_ESCALATED, $alert->fresh()->notification_state);
        $this->assertDatabaseHas('ops_notification_deliveries', [
            'ops_alert_id' => $alert->id,
            'event_type' => 'ops.alert.escalated',
        ]);

        $index = $this->get(route('admin.notifications.index', [
            'status' => OpsNotificationDelivery::STATUS_DELIVERED,
            'feed_profile_id' => $feedProfile->id,
        ]));
        $index->assertOk()->assertSee('Notification Center')->assertSee('ops.alert.escalated');

        $delivery = OpsNotificationDelivery::query()->where('event_type', 'ops.alert.escalated')->latest('id')->firstOrFail();
        $this->assertSame($admin->shop_id, $delivery->shop_id);

        $this->get(route('admin.notifications.deliveries.show', ['ops_notification_delivery' => $delivery->id]))
            ->assertOk()
            ->assertSee((string) $delivery->id)
            ->assertSee('ops.alert.escalated');
    }

    public function test_notification_commands_report_status_and_process_pending_retries(): void
    {
        ['feedProfile' => $feedProfile, 'admin' => $admin, 'generation' => $generation] = $this->seedPublishedHypercareContext();
        $service = app(NotificationCenterService::class);

        $service->saveRoute($feedProfile->shop, [
            'name' => 'Retryable webhook',
            'scope' => 'feed_profile',
            'feed_profile_id' => $feedProfile->id,
            'channel' => 'webhook',
            'event_family' => 'publish_failed',
            'minimum_severity' => 'warning',
            'target_value' => 'https://hooks.example.test/retry-command',
            'max_attempts' => 3,
            'timeout_seconds' => 5,
        ], null, $admin);

        Http::fake(function (Request $request) {
            return Http::response(['error' => 'boom'], 500);
        });

        $alert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_PUBLISH_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'Publish failed',
            'Boom.',
            [],
            $generation,
            $feedProfile->currentHypercareWindow,
            'command-retry'
        );

        $delivery = OpsNotificationDelivery::query()->where('ops_alert_id', $alert->id)->firstOrFail();
        $delivery->update(['next_retry_at' => now()->subMinute()]);

        Http::fake(function (Request $request) {
            return Http::response(['ok' => true], 200);
        });

        $this->artisan('ops:alerts:dispatch-pending')
            ->expectsOutputToContain('Processed deliveries:')
            ->assertSuccessful();

        $this->artisan('ops:channels:status', ['--shop' => $feedProfile->shop_id])
            ->expectsOutputToContain('Retryable webhook')
            ->assertSuccessful();

        $this->artisan('ops:notify:test', [
            'channel' => 'log',
            '--shop' => $feedProfile->shop_id,
            '--target' => 'stack',
        ])->expectsOutputToContain('Test delivery')->assertSuccessful();
    }
}
