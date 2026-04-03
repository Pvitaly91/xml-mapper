<?php

namespace App\Services\Ops;

use App\Models\FeedbackImport;
use App\Models\FeedGeneration;
use App\Models\FeedGenerationPreviewLink;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneService
{
    public function __construct(
        private readonly OpsRunService $opsRunService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?User $user = null): array
    {
        $run = $this->opsRunService->start(OpsRun::TYPE_PRUNE, user: $user);

        try {
            $summary = [
                'generation_artifacts_pruned' => $this->pruneGenerationArtifacts(),
                'preview_links_pruned' => $this->prunePreviewLinks(),
                'smoke_checks_pruned' => $this->pruneSmokeChecks(),
                'feedback_artifacts_pruned' => $this->pruneFeedbackArtifacts(),
                'qa_bundles_pruned' => $this->pruneDirectoryFiles(
                    trim(config('feed_mediator.builds_directory'), '/'),
                    '/qa-bundle-generation-.*\.zip$/',
                    (int) config('feed_mediator.retention.qa_bundles_days')
                ),
                'runbooks_pruned' => $this->pruneDirectoryFiles(
                    trim(config('feed_mediator.runbooks_directory'), '/'),
                    '/runbook-.*\.md$/',
                    (int) config('feed_mediator.retention.runbooks_days')
                ),
                'ops_runs_pruned' => $this->pruneOpsRuns(),
            ];
            $run = $this->opsRunService->finish($run, OpsRun::STATUS_SUCCEEDED, $summary);

            return [
                'run' => $run,
                'summary' => $summary,
            ];
        } catch (Throwable $exception) {
            $this->opsRunService->fail($run, $exception);

            throw $exception;
        }
    }

    private function pruneGenerationArtifacts(): int
    {
        $threshold = now()->subDays((int) config('feed_mediator.retention.generation_artifacts_days'));
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $protectedGenerationIds = FeedProfile::query()
            ->whereNotNull('published_generation_id')
            ->pluck('published_generation_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $count = 0;

        FeedGeneration::query()
            ->whereNotNull('file_path')
            ->where('built_at', '<', $threshold)
            ->where('release_status', '!=', FeedGeneration::RELEASE_STATUS_PUBLISHED)
            ->orderBy('id')
            ->chunkById(100, function ($generations) use (&$count, $disk, $protectedGenerationIds): void {
                foreach ($generations as $generation) {
                    if (in_array($generation->id, $protectedGenerationIds, true)) {
                        continue;
                    }

                    if ($generation->file_path && $disk->exists($generation->file_path)) {
                        $disk->delete($generation->file_path);
                    }

                    $meta = $generation->meta ?? [];
                    $meta['artifact_pruned_at'] = now()->toIso8601String();
                    $meta['artifact_pruned_reason'] = 'retention';

                    $generation->forceFill([
                        'file_path' => null,
                        'meta' => $meta,
                    ])->save();

                    $count++;
                }
            });

        return $count;
    }

    private function prunePreviewLinks(): int
    {
        $threshold = now()->subDays((int) config('feed_mediator.retention.preview_links_days'));
        $links = FeedGenerationPreviewLink::query()
            ->where(function ($query) use ($threshold): void {
                $query->where(function ($inner) use ($threshold): void {
                    $inner->whereNotNull('revoked_at')->where('revoked_at', '<', $threshold);
                })->orWhere(function ($inner) use ($threshold): void {
                    $inner->whereNotNull('expires_at')->where('expires_at', '<', $threshold);
                });
            })
            ->get();
        $count = $links->count();
        $links->each->delete();

        return $count;
    }

    private function pruneSmokeChecks(): int
    {
        $threshold = now()->subDays((int) config('feed_mediator.retention.smoke_checks_days'));
        $latestIds = FeedGenerationSmokeCheck::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('feed_generation_id')
            ->pluck('id')
            ->all();

        return FeedGenerationSmokeCheck::query()
            ->where('checked_at', '<', $threshold)
            ->whereNotIn('id', $latestIds)
            ->delete();
    }

    private function pruneFeedbackArtifacts(): int
    {
        $threshold = now()->subDays((int) config('feed_mediator.retention.feedback_artifacts_days'));
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $count = 0;

        FeedbackImport::query()
            ->whereNotNull('source_path')
            ->where('imported_at', '<', $threshold)
            ->orderBy('id')
            ->chunkById(100, function ($imports) use (&$count, $disk): void {
                foreach ($imports as $import) {
                    if ($import->source_path && $disk->exists($import->source_path)) {
                        $disk->delete($import->source_path);
                    }

                    $meta = $import->meta ?? [];
                    $meta['artifact_pruned_at'] = now()->toIso8601String();

                    $import->forceFill([
                        'source_path' => null,
                        'meta' => $meta,
                    ])->save();

                    $count++;
                }
            });

        return $count;
    }

    private function pruneDirectoryFiles(string $directory, string $pattern, int $days): int
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $threshold = now()->subDays($days)->getTimestamp();
        $count = 0;

        foreach ($disk->allFiles($directory) as $file) {
            if (! preg_match($pattern, basename($file))) {
                continue;
            }

            $absolutePath = $disk->path($file);
            $modifiedAt = is_file($absolutePath) ? filemtime($absolutePath) : false;

            if ($modifiedAt === false || $modifiedAt >= $threshold) {
                continue;
            }

            $disk->delete($file);
            $count++;
        }

        return $count;
    }

    private function pruneOpsRuns(): int
    {
        $threshold = now()->subDays((int) config('feed_mediator.retention.ops_runs_days'));

        return OpsRun::query()
            ->whereIn('type', [OpsRun::TYPE_PREFLIGHT, OpsRun::TYPE_BENCHMARK])
            ->where('started_at', '<', $threshold)
            ->delete();
    }
}
