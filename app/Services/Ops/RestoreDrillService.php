<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class RestoreDrillService
{
    public function __construct(
        private readonly OpsRunService $opsRunService,
        private readonly OpsStatusService $opsStatusService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(FeedProfile $feedProfile, ?User $user = null, ?string $note = null): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $run = $this->opsRunService->start(OpsRun::TYPE_RESTORE_DRILL, $feedProfile->shop, $feedProfile, $user, [
            'note' => $note,
        ]);
        $latestDbBackup = OpsRun::query()->where('type', OpsRun::TYPE_BACKUP_DB)->latest('started_at')->first();
        $latestFilesBackup = OpsRun::query()->where('type', OpsRun::TYPE_BACKUP_FILES)->latest('started_at')->first();
        $publishedPath = $feedProfile->published_path;
        $checks = [
            $this->check('db_backup', $latestDbBackup instanceof OpsRun && filled($latestDbBackup->artifact_path), 'Latest DB backup is available.'),
            $this->check('files_backup', $latestFilesBackup instanceof OpsRun && filled($latestFilesBackup->artifact_path), 'Latest files backup is available.'),
            $this->check('shared_storage', $publishedPath === null || $disk->exists($publishedPath), 'Published feed artifact is available on shared storage.'),
            $this->check('health', $this->opsStatusService->overallStatus($feedProfile->shop) !== 'failed', 'Ops health snapshot is reachable.'),
        ];
        $blocking = collect($checks)->where('status', 'failed')->pluck('message')->values()->all();
        $content = $this->render($feedProfile, $checks, $note, $latestDbBackup, $latestFilesBackup);
        $relativePath = trim(config('feed_mediator.runbooks_directory'), '/')
            .'/shop-'.$feedProfile->shop_id
            .'/feed-'.$feedProfile->id
            .'/restore-drill-'.now()->format('YmdHis').'.md';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);
        $status = $blocking === [] ? OpsRun::STATUS_SUCCEEDED : OpsRun::STATUS_FAILED;
        $run = $this->opsRunService->finish($run, $status, [
            'checks_total' => count($checks),
            'failed_checks' => count($blocking),
        ], [
            'checks' => $checks,
            'blocking_issues' => $blocking,
            'note' => $note,
        ], $relativePath, strlen($content));

        return [
            'run' => $run,
            'checks' => $checks,
            'blocking_issues' => $blocking,
            'report_path' => $relativePath,
            'report_absolute_path' => $absolutePath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(FeedProfile $feedProfile): array
    {
        return [
            'latest' => $feedProfile->opsRuns()
                ->where('type', OpsRun::TYPE_RESTORE_DRILL)
                ->latest('started_at')
                ->first(),
            'history' => $feedProfile->opsRuns()
                ->where('type', OpsRun::TYPE_RESTORE_DRILL)
                ->latest('started_at')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $key, bool $ok, string $message): array
    {
        return [
            'key' => $key,
            'status' => $ok ? 'ok' : 'failed',
            'message' => $message,
        ];
    }

    private function render(FeedProfile $feedProfile, array $checks, ?string $note, ?OpsRun $latestDbBackup, ?OpsRun $latestFilesBackup): string
    {
        $lines = [
            '# Restore Drill Checklist',
            '',
            'Shop: '.($feedProfile->shop?->name ?: 'n/a'),
            'Feed profile: '.$feedProfile->name.' ('.$feedProfile->code.')',
            'Generated at: '.now()->toDateTimeString(),
            'Operator note: '.($note ?: 'n/a'),
            'Latest DB backup: '.($latestDbBackup?->artifact_path ?: 'n/a'),
            'Latest files backup: '.($latestFilesBackup?->artifact_path ?: 'n/a'),
            '',
            '## Checklist',
        ];

        foreach ($checks as $check) {
            $lines[] = '- ['.($check['status'] === 'ok' ? 'x' : ' ').'] '.$check['message'];
        }

        $lines[] = '';
        $lines[] = '## Restore Verification Notes';
        $lines[] = '- Verify DB restore on an isolated host or disposable schema.';
        $lines[] = '- Verify shared storage mount and published feed artifact availability.';
        $lines[] = '- Verify the health endpoint and worker/scheduler heartbeats after restore.';
        $lines[] = '- This workflow does not execute destructive restore actions on the live host.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
