<?php

namespace App\Services\Ops;

use App\Actions\Ops\ResolveDueFeedBuildsAction;
use App\Actions\Ops\ResolveDueFeedPublishesAction;
use App\Actions\Ops\ResolveDueSourceConnectionsAction;
use App\Models\FeedGeneration;
use App\Models\Shop;
use App\Models\SourceImport;
use App\Services\Setup\DatabaseSetupInspector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class OpsStatusService
{
    public function __construct(
        private readonly HeartbeatService $heartbeatService,
        private readonly ResolveDueSourceConnectionsAction $resolveDueSourceConnections,
        private readonly ResolveDueFeedBuildsAction $resolveDueFeedBuilds,
        private readonly ResolveDueFeedPublishesAction $resolveDueFeedPublishes,
        private readonly DatabaseSetupInspector $databaseSetupInspector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Shop $shop = null): array
    {
        $schedulerHeartbeat = $this->heartbeatStatus($this->heartbeatService->schedulerHeartbeat(), true);
        $workerHeartbeat = config('queue.default') === 'sync'
            ? $this->notApplicableHeartbeat()
            : $this->heartbeatStatus($this->heartbeatService->workerHeartbeat(), true);
        $failedJobsCount = $this->failedJobsCount();
        $failedJobsStatus = $failedJobsCount >= (int) config('feed_mediator.ops.failed_jobs_degraded_threshold')
            ? 'degraded'
            : 'ok';

        return [
            'queue_mode' => (string) config('queue.default'),
            'queue_names' => config('feed_mediator.queues'),
            'scheduler_heartbeat' => $schedulerHeartbeat,
            'worker_heartbeat' => $workerHeartbeat,
            'failed_jobs' => [
                'status' => $failedJobsStatus,
                'count' => $failedJobsCount,
            ],
            'due_source_connections_count' => $this->databaseSetupInspector->canResolveDueSourceConnections()
                ? $this->safeCount(fn () => $this->resolveDueSourceConnections->handle($shop)->count())
                : 0,
            'due_feed_builds_count' => $this->databaseSetupInspector->canResolveDueFeedBuilds()
                ? $this->safeCount(fn () => $this->resolveDueFeedBuilds->handle($shop)->count())
                : 0,
            'due_feed_publishes_count' => $this->databaseSetupInspector->canResolveDueFeedPublishes()
                ? $this->safeCount(fn () => $this->resolveDueFeedPublishes->handle($shop)->count())
                : 0,
            'last_successful_sync' => $this->databaseSetupInspector->canReadLastSuccessfulSync()
                ? $this->safeFirst(fn () => SourceImport::query()
                    ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                    ->where('status', SourceImport::STATUS_NORMALIZED)
                    ->latest('finished_at')
                    ->first())
                : null,
            'last_successful_build' => $this->databaseSetupInspector->canReadLastSuccessfulBuild()
                ? $this->safeFirst(fn () => FeedGeneration::query()
                    ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                    ->whereNotNull('built_at')
                    ->latest('built_at')
                    ->first())
                : null,
            'last_successful_publish' => $this->databaseSetupInspector->canReadLastSuccessfulPublish()
                ? $this->safeFirst(fn () => FeedGeneration::query()
                    ->when($shop !== null, fn ($query) => $query->where('shop_id', $shop->id))
                    ->whereNotNull('published_at')
                    ->latest('published_at')
                    ->first())
                : null,
        ];
    }

    public function overallStatus(?Shop $shop = null): string
    {
        $snapshot = $this->snapshot($shop);

        foreach (['scheduler_heartbeat', 'worker_heartbeat', 'failed_jobs'] as $key) {
            $status = $snapshot[$key]['status'] ?? 'ok';

            if (in_array($status, ['failed', 'degraded'], true)) {
                return 'degraded';
            }
        }

        return 'ok';
    }

    /**
     * @return array{status:string,last_seen_at:?CarbonImmutable,age_seconds:?int}
     */
    private function heartbeatStatus(?CarbonImmutable $timestamp, bool $degradeWhenMissing): array
    {
        if ($timestamp === null) {
            return [
                'status' => $degradeWhenMissing ? 'degraded' : 'unknown',
                'last_seen_at' => null,
                'age_seconds' => null,
            ];
        }

        $ageSeconds = $timestamp->diffInSeconds(CarbonImmutable::now());

        return [
            'status' => $ageSeconds > (int) config('feed_mediator.ops.heartbeat_stale_after_seconds') ? 'degraded' : 'ok',
            'last_seen_at' => $timestamp,
            'age_seconds' => $ageSeconds,
        ];
    }

    /**
     * @return array{status:string,last_seen_at:null,age_seconds:null}
     */
    private function notApplicableHeartbeat(): array
    {
        return [
            'status' => 'not_applicable',
            'last_seen_at' => null,
            'age_seconds' => null,
        ];
    }

    private function failedJobsCount(): int
    {
        try {
            return (int) DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function safeCount(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (Throwable) {
            return 0;
        }
    }

    private function safeFirst(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
