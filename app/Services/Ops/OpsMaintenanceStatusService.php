<?php

namespace App\Services\Ops;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationPreviewLink;
use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\PerformanceRun;
use App\Models\Shop;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OpsMaintenanceStatusService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(?Shop $shop = null, ?FeedProfile $feedProfile = null): array
    {
        return [
            'last_preflight' => $this->latestRun(OpsRun::TYPE_PREFLIGHT, $shop, $feedProfile),
            'last_backup_db' => $this->latestRun(OpsRun::TYPE_BACKUP_DB),
            'last_backup_files' => $this->latestRun(OpsRun::TYPE_BACKUP_FILES),
            'last_prune' => $this->latestRun(OpsRun::TYPE_PRUNE),
            'last_benchmark' => $this->latestRun(OpsRun::TYPE_BENCHMARK, $shop, $feedProfile),
            'last_performance_run' => $this->latestPerformanceRun($shop, $feedProfile),
            'last_deploy' => $this->latestRun(OpsRun::TYPE_DEPLOY),
            'last_rollback' => $this->latestRun(OpsRun::TYPE_ROLLBACK),
            'storage' => $this->storageSummary(),
            'queue_backlog' => $this->queueBacklog(),
            'retention_warnings' => $this->retentionWarnings($shop, $feedProfile),
        ];
    }

    private function latestRun(string $type, ?Shop $shop = null, ?FeedProfile $feedProfile = null): ?OpsRun
    {
        return OpsRun::query()
            ->where('type', $type)
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
            ->latest('started_at')
            ->first();
    }

    private function latestPerformanceRun(?Shop $shop = null, ?FeedProfile $feedProfile = null): ?PerformanceRun
    {
        return PerformanceRun::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
            ->latest('started_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function storageSummary(): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $directories = [
            'imports' => trim(config('feed_mediator.imports_directory'), '/'),
            'builds' => trim(config('feed_mediator.builds_directory'), '/'),
            'published' => trim(config('feed_mediator.published_directory'), '/'),
            'feedback' => trim(config('feed_mediator.feedback_directory'), '/'),
            'runbooks' => trim(config('feed_mediator.runbooks_directory'), '/'),
            'backups_db' => trim(config('feed_mediator.backups.db_directory'), '/'),
            'backups_files' => trim(config('feed_mediator.backups.files_directory'), '/'),
        ];
        $perDirectory = [];
        $totalBytes = 0;

        foreach ($directories as $label => $directory) {
            $bytes = 0;

            foreach ($disk->allFiles($directory) as $file) {
                try {
                    $bytes += (int) $disk->size($file);
                } catch (Throwable) {
                    // Keep the dashboard resilient when a single file stat fails.
                }
            }

            $perDirectory[$label] = $bytes;
            $totalBytes += $bytes;
        }

        return [
            'disk' => config('feed_mediator.storage_disk'),
            'total_bytes' => $totalBytes,
            'directories' => $perDirectory,
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function queueBacklog(): array
    {
        $result = [];

        foreach ((array) config('feed_mediator.queues') as $label => $queueName) {
            try {
                $result[$label] = Queue::size($queueName);
            } catch (Throwable) {
                $result[$label] = null;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function retentionWarnings(?Shop $shop = null, ?FeedProfile $feedProfile = null): array
    {
        $warnings = [];
        $storageSummary = $this->storageSummary();
        $storageWarningBytes = (int) config('feed_mediator.performance.storage_warning_bytes');

        if ($storageWarningBytes > 0 && $storageSummary['total_bytes'] >= $storageWarningBytes) {
            $warnings[] = sprintf(
                'Storage usage reached %s bytes, which is above the configured warning threshold.',
                number_format($storageSummary['total_bytes'])
            );
        }

        $stalePreviewCount = FeedGenerationPreviewLink::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
            ->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->whereNotNull('expires_at')->where('expires_at', '<', now());
                })->orWhere(function ($inner): void {
                    $inner->whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays((int) config('feed_mediator.retention.preview_links_days')));
                });
            })
            ->count();

        if ($stalePreviewCount > 0) {
            $warnings[] = sprintf('%d stale preview links are ready for prune.', $stalePreviewCount);
        }

        $oldGenerationCount = FeedGeneration::query()
            ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
            ->when($feedProfile !== null, fn ($query) => $query->where('feed_profile_id', $feedProfile->id))
            ->whereNotNull('file_path')
            ->where('built_at', '<', now()->subDays((int) config('feed_mediator.retention.generation_artifacts_days')))
            ->where('release_status', '!=', FeedGeneration::RELEASE_STATUS_PUBLISHED)
            ->count();

        if ($oldGenerationCount > 0) {
            $warnings[] = sprintf('%d old generation artifacts are ready for prune.', $oldGenerationCount);
        }

        return $warnings;
    }
}
