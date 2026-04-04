<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsNotificationDelivery;
use App\Notifications\OpsAlertNotification;
use App\Services\Ops\NotificationCenterService;
use App\Services\Ops\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class NotificationDeliveryWorkflowTest extends TestCase
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

    public function test_database_log_email_and_webhook_channels_deliver_through_abstraction(): void
    {
        ['feedProfile' => $feedProfile, 'admin' => $admin, 'generation' => $generation] = $this->seedPublishedHypercareContext();
        $service = app(NotificationCenterService::class);
        $shop = $feedProfile->shop;

        Notification::fake();
        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'hooks.example.test')
                ? Http::response(['ok' => true], 200)
                : Http::response(['error' => 'not-found'], 404);
        });

        foreach ([
            ['name' => 'DB publish', 'channel' => 'database', 'target_value' => ''],
            ['name' => 'Log publish', 'channel' => 'log', 'target_value' => 'stack'],
            ['name' => 'Email publish', 'channel' => 'email', 'target_value' => $admin->email],
            ['name' => 'Webhook publish', 'channel' => 'webhook', 'target_value' => 'https://hooks.example.test/publish'],
        ] as $payload) {
            $service->saveRoute($shop, array_merge($payload, [
                'scope' => 'feed_profile',
                'feed_profile_id' => $feedProfile->id,
                'event_family' => 'publish_failed',
                'minimum_severity' => 'warning',
                'max_attempts' => 3,
                'timeout_seconds' => 5,
            ]), null, $admin);
        }

        $alert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_PUBLISH_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'Feed publish failed',
            'Publish exploded.',
            [],
            $generation,
            $feedProfile->currentHypercareWindow,
            'publish-failure-e2e'
        );

        $this->assertSame(4, OpsNotificationDelivery::query()->where('ops_alert_id', $alert->id)->count());
        $this->assertDatabaseHas('ops_notification_deliveries', [
            'ops_alert_id' => $alert->id,
            'channel' => 'database',
            'status' => OpsNotificationDelivery::STATUS_DELIVERED,
        ]);
        $this->assertDatabaseHas('ops_notification_deliveries', [
            'ops_alert_id' => $alert->id,
            'channel' => 'log',
            'status' => OpsNotificationDelivery::STATUS_DELIVERED,
        ]);
        $this->assertDatabaseHas('ops_notification_deliveries', [
            'ops_alert_id' => $alert->id,
            'channel' => 'email',
            'status' => OpsNotificationDelivery::STATUS_DELIVERED,
        ]);
        $this->assertDatabaseHas('ops_notification_deliveries', [
            'ops_alert_id' => $alert->id,
            'channel' => 'webhook',
            'status' => OpsNotificationDelivery::STATUS_DELIVERED,
        ]);
        $this->assertSame(OpsAlert::NOTIFICATION_DELIVERED, $alert->fresh()->notification_state);

        Notification::assertSentTo(
            $admin,
            OpsAlertNotification::class,
            fn (OpsAlertNotification $notification) => ($notification->toArray($admin)['alert_id'] ?? null) === $alert->id
        );
    }

    public function test_failed_delivery_is_persisted_and_retry_works(): void
    {
        ['feedProfile' => $feedProfile, 'admin' => $admin, 'generation' => $generation] = $this->seedPublishedHypercareContext();
        $service = app(NotificationCenterService::class);

        $route = $service->saveRoute($feedProfile->shop, [
            'name' => 'Webhook publish',
            'scope' => 'feed_profile',
            'feed_profile_id' => $feedProfile->id,
            'channel' => 'webhook',
            'event_family' => 'publish_failed',
            'minimum_severity' => 'warning',
            'target_value' => 'https://hooks.example.test/retry-me',
            'max_attempts' => 3,
            'timeout_seconds' => 5,
        ], null, $admin);

        Http::fakeSequence()
            ->push(['error' => 'boom'], 500)
            ->push(['ok' => true], 200);

        $alert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_PUBLISH_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'Feed publish failed',
            'Publish exploded.',
            [],
            $generation,
            $feedProfile->currentHypercareWindow,
            'publish-failure-retry'
        );

        $delivery = OpsNotificationDelivery::query()->where('ops_alert_id', $alert->id)->firstOrFail();

        $this->assertSame(OpsNotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertNotNull($delivery->next_retry_at);

        $retried = $service->retry($delivery);

        $this->assertSame(OpsNotificationDelivery::STATUS_DELIVERED, $retried->status);
        $this->assertSame(2, $retried->attempts);
        $this->assertSame(OpsNotificationDelivery::STATUS_DELIVERED, $route->fresh()->last_delivery_status);
    }
}
