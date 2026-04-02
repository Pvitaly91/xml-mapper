<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\SourceConnection;
use App\Models\ValidationError;
use App\Services\Ops\OpsStatusService;
use App\Services\Setup\DatabaseSetupInspector;
use Carbon\CarbonInterface;

class FeedReleaseReadinessService
{
    public function __construct(
        private readonly FeedPilotReadinessService $pilotReadinessService,
        private readonly FeedPublishGuardService $publishGuardService,
        private readonly OpsStatusService $opsStatusService,
        private readonly DatabaseSetupInspector $databaseSetupInspector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(FeedProfile $feedProfile, FeedGeneration $generation): array
    {
        $feedProfile->loadMissing(['sourceConnection.latestImport', 'publishedGeneration']);
        $generation->loadMissing('smokeChecks');

        $blocking = [];
        $warnings = [];
        $nextSteps = [];
        $checks = [];
        $pilot = $this->pilotReadinessService->summarize($feedProfile);
        $guard = $this->publishGuardService->evaluate($feedProfile, $generation);
        $connection = $feedProfile->sourceConnection;
        $schema = $this->databaseSetupInspector->dashboardReport();
        $opsStatus = $this->opsStatusService->overallStatus($feedProfile->shop);
        $latestSmokeCheck = $generation->smokeChecks()->latest('checked_at')->first();
        $publishedSmokeCheck = $feedProfile->publishedGeneration?->smokeChecks()->latest('checked_at')->first();
        $lastSyncFresh = $this->isFresh($connection?->last_synced_at, $connection?->sync_interval_minutes);
        $criticalConformanceErrors = ValidationError::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('is_active', true)
            ->whereIn('code', ValidationError::criticalConformanceCodes())
            ->whereHas('feedItem', fn ($query) => $query->where('last_built_generation_id', $generation->id))
            ->count();

        $checks['generation_built'] = [
            'ok' => in_array($generation->status, [FeedGeneration::STATUS_BUILT, FeedGeneration::STATUS_PUBLISHED], true) && ! blank($generation->file_path),
            'label' => 'Generation is built and has a file.',
        ];
        if (! $checks['generation_built']['ok']) {
            $blocking[] = 'Build the generation before release.';
            $nextSteps[] = 'Run feed build for this profile.';
        }

        $checks['source_healthy'] = [
            'ok' => $connection instanceof SourceConnection
                && ! in_array($connection->last_connection_check_status, [SourceConnection::CHECK_STATUS_AUTH_FAILED, SourceConnection::CHECK_STATUS_CONFIG_ERROR], true)
                && ! in_array($connection->last_sync_status, [SourceConnection::CHECK_STATUS_AUTH_FAILED, SourceConnection::CHECK_STATUS_CONFIG_ERROR], true),
            'label' => 'Source connection health is acceptable.',
        ];
        if (! $checks['source_healthy']['ok']) {
            $blocking[] = 'Source connection is unhealthy or authentication is broken.';
            $nextSteps[] = 'Run Test connection and fix source credentials before publishing.';
        }

        $checks['last_sync_fresh'] = [
            'ok' => $lastSyncFresh,
            'label' => 'Latest source sync is still fresh.',
        ];
        if (! $checks['last_sync_fresh']['ok']) {
            $blocking[] = 'Latest source sync is stale.';
            $nextSteps[] = 'Run a fresh source sync before publishing.';
        }

        $checks['dictionaries_imported'] = [
            'ok' => KastaCategory::query()->count() > 0 && KastaAttribute::query()->count() > 0 && KastaAttributeValue::query()->count() > 0,
            'label' => 'Kasta dictionaries are imported.',
        ];
        if (! $checks['dictionaries_imported']['ok']) {
            $blocking[] = 'Kasta dictionaries are missing.';
            $nextSteps[] = 'Import Kasta dictionaries before release.';
        }

        $checks['mappings_complete'] = [
            'ok' => $pilot['mappings_complete']['ok'],
            'label' => 'Mappings are sufficiently complete.',
        ];
        if (! $checks['mappings_complete']['ok']) {
            $blocking[] = 'Category or attribute mappings are still incomplete.';
            $nextSteps[] = 'Review mapping diagnostics and finish missing mappings.';
        }

        $checks['critical_conformance'] = [
            'ok' => $criticalConformanceErrors === 0,
            'label' => 'No critical conformance errors remain.',
            'count' => $criticalConformanceErrors,
        ];
        if ($criticalConformanceErrors > 0) {
            $blocking[] = sprintf('Critical conformance errors remain on %d item(s).', $criticalConformanceErrors);
            $nextSteps[] = 'Fix invalid color, size, vendor code, or unstable export key issues.';
        }

        $checks['publish_guard'] = [
            'ok' => $guard['allowed'],
            'label' => 'Publish guardrails pass.',
            'summary' => $guard['summary'],
            'reasons' => $guard['reasons'],
        ];
        if (! $guard['allowed']) {
            array_push($blocking, ...$guard['reasons']);
        }

        $checks['generation_diff'] = [
            'ok' => isset($generation->meta['diff']['summary']),
            'label' => 'Generation diff is available.',
        ];
        if (! $checks['generation_diff']['ok']) {
            $warnings[] = 'Generation diff is not available yet.';
        }

        $checks['approval'] = [
            'ok' => in_array($generation->release_status, [FeedGeneration::RELEASE_STATUS_APPROVED, FeedGeneration::RELEASE_STATUS_PUBLISHED], true),
            'label' => 'Generation is approved for release.',
        ];
        if (! $checks['approval']['ok']) {
            $blocking[] = 'Generation must be approved before publishing.';
            $nextSteps[] = 'Approve the release candidate before publishing.';
        }

        $checks['published_smoke_check'] = [
            'ok' => $publishedSmokeCheck === null || $publishedSmokeCheck->status !== 'failed',
            'label' => 'Latest published smoke check is acceptable.',
            'latest' => $publishedSmokeCheck,
        ];
        if ($publishedSmokeCheck?->status === 'failed') {
            $warnings[] = 'Latest smoke check failed. Review the published feed before go-live.';
        }

        $checks['candidate_smoke_check'] = [
            'ok' => $latestSmokeCheck === null || $latestSmokeCheck->status !== 'failed',
            'label' => 'Selected generation smoke check is acceptable.',
            'latest' => $latestSmokeCheck,
        ];

        $checks['schema_ready'] = [
            'ok' => $schema['schema_ready'],
            'label' => 'Application schema is ready.',
        ];
        if (! $schema['schema_ready']) {
            $blocking[] = 'Database schema is not fully ready.';
            $nextSteps[] = 'Run php artisan migrate and php artisan app:doctor.';
        }

        $checks['ops_healthy'] = [
            'ok' => $opsStatus === 'ok',
            'label' => 'Ops status is healthy enough.',
        ];
        if ($opsStatus !== 'ok') {
            $warnings[] = 'Ops status is degraded. Review dashboard heartbeats and failed jobs.';
        }

        $overall = $blocking !== []
            ? 'blocked'
            : ($warnings !== [] ? 'warning' : 'ready');

        return [
            'status' => $overall,
            'blocking_issues' => array_values(array_unique($blocking)),
            'warnings' => array_values(array_unique($warnings)),
            'next_steps' => array_values(array_unique($nextSteps)),
            'checks' => $checks,
            'publish_guard' => $guard,
        ];
    }

    private function isFresh(?CarbonInterface $timestamp, ?int $syncIntervalMinutes): bool
    {
        if ($timestamp === null) {
            return false;
        }

        $staleAfterMinutes = max(30, ((int) ($syncIntervalMinutes ?? 60)) * 2);

        return $timestamp->gt(now()->subMinutes($staleAfterMinutes));
    }
}
