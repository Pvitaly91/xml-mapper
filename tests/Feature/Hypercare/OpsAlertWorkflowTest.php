<?php

namespace Tests\Feature\Hypercare;

use App\Models\OpsAlert;
use App\Services\Ops\HypercarePolicyService;
use App\Services\Ops\OpsAlertService;
use App\Services\Ops\SilenceWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\Concerns\CreatesPublishedHypercareContext;
use Tests\TestCase;

class OpsAlertWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use CreatesPublishedHypercareContext;
    use RefreshDatabase;

    public function test_policy_failures_raise_alerts_and_acknowledge_resolve_flow_works(): void
    {
        ['feedProfile' => $feedProfile, 'hypercare' => $hypercare, 'admin' => $admin] = $this->seedPublishedHypercareContext();

        $feedProfile->sourceConnection->update([
            'last_synced_at' => now()->subHours(12),
            'last_sync_status' => 'failed',
        ]);

        app(HypercarePolicyService::class)->review($feedProfile->fresh(['sourceConnection']), $hypercare->fresh());

        $alert = OpsAlert::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('fingerprint', 'policy:source_sync_freshness')
            ->first();

        $this->assertNotNull($alert);
        $this->assertContains($alert->severity, [OpsAlert::SEVERITY_WARNING, OpsAlert::SEVERITY_CRITICAL]);

        $acknowledged = app(OpsAlertService::class)->acknowledge($alert->fresh(), 'On it', 'Investigating', $admin);
        $resolved = app(OpsAlertService::class)->resolve($acknowledged->fresh(), 'Fixed upstream issue', null, $admin);

        $this->assertSame(OpsAlert::STATE_ACKNOWLEDGED, $acknowledged->state);
        $this->assertSame(OpsAlert::STATE_RESOLVED, $resolved->state);
    }

    public function test_escalation_triggers_and_silence_windows_only_suppress_non_critical_alerts(): void
    {
        ['feedProfile' => $feedProfile, 'generation' => $generation, 'hypercare' => $hypercare, 'admin' => $admin] = $this->seedPublishedHypercareContext();

        app(SilenceWindowService::class)->start($feedProfile, now()->subMinute(), now()->addHour(), OpsAlert::SEVERITY_CRITICAL, 'Planned maintenance', $admin);

        $warningAlert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_QUEUE_BACKLOG,
            OpsAlert::SEVERITY_WARNING,
            'Queue lag warning',
            'Queue backlog is elevated.',
            [],
            $generation,
            $hypercare
        );
        $criticalAlert = app(OpsAlertService::class)->raiseForProfile(
            $feedProfile,
            OpsAlert::SOURCE_PUBLISH_FAILURE,
            OpsAlert::SEVERITY_CRITICAL,
            'Critical publish issue',
            'Publish failed hard.',
            [],
            $generation,
            $hypercare,
            'critical-publish'
        );

        $this->assertSame(OpsAlert::STATE_SILENCED, $warningAlert->state);
        $this->assertSame(OpsAlert::STATE_RAISED, $criticalAlert->state);

        $criticalAlert->update(['last_raised_at' => now()->subMinutes(30)]);

        $escalated = app(OpsAlertService::class)->escalateDue(null, $feedProfile->fresh());

        $this->assertTrue($escalated->contains('id', $criticalAlert->id));
        $this->assertSame(OpsAlert::STATE_ESCALATED, $criticalAlert->fresh()->state);
    }
}
