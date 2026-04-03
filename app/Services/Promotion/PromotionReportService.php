<?php

namespace App\Services\Promotion;

use App\Models\PromotionRun;
use App\Models\PromotionSnapshot;
use Illuminate\Support\Facades\Storage;

class PromotionReportService
{
    public function __construct(
        private readonly PromotionSnapshotService $snapshotService,
    ) {}

    public function markdown(PromotionRun $run): string
    {
        $summary = $run->summary ?? [];
        $plan = (array) ($summary['plan'] ?? []);
        $drift = (array) ($summary['drift'] ?? []);
        $lines = [
            '# Promotion Run',
            '',
            'Run ID: '.$run->id,
            'Mode: '.$run->mode,
            'Strategy: '.($run->strategy ?: 'n/a'),
            'Status: '.$run->status,
            'Source environment: '.($run->source_environment ?: 'n/a'),
            'Target environment: '.($run->target_environment ?: 'n/a'),
            'Source snapshot checksum: '.($run->sourceSnapshot?->checksum ?: 'n/a'),
            'Started at: '.optional($run->started_at)->toIso8601String(),
            'Finished at: '.optional($run->finished_at)->toIso8601String(),
            '',
            '## Summary',
            '- Created: '.(data_get($plan, 'summary.created', data_get($summary, 'created', 0))),
            '- Updated: '.(data_get($plan, 'summary.updated', data_get($summary, 'updated', 0))),
            '- Skipped: '.(data_get($plan, 'summary.skipped', data_get($summary, 'skipped', 0))),
            '- Conflicts: '.(data_get($plan, 'summary.conflicts', data_get($summary, 'conflicts', 0))),
            '- Blocking errors: '.(data_get($plan, 'summary.blocking_errors', 0)),
            '- Drift status: '.($drift['status'] ?? 'n/a'),
            '',
            '## Warnings',
        ];

        foreach (($run->warnings ?? []) !== [] ? (array) $run->warnings : ['None.'] as $warning) {
            $lines[] = '- '.$warning;
        }

        $lines[] = '';
        $lines[] = '## Errors';

        foreach (($run->errors ?? []) !== [] ? (array) $run->errors : ['None.'] as $error) {
            $lines[] = '- '.$error;
        }

        $lines[] = '';
        $lines[] = '## Secret Rebind';
        $lines[] = '- Required: '.(data_get($plan, 'secret_rebind.required', data_get($summary, 'secret_rebind.required', false)) ? 'yes' : 'no');
        $lines[] = '- State: '.(data_get($plan, 'secret_rebind.state', data_get($summary, 'secret_rebind.state', 'n/a')));

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return array<string, mixed>
     */
    public function store(PromotionRun $run): array
    {
        $content = $this->markdown($run);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.promotion.reports_directory', config('feed_mediator.runbooks_directory')), '/')
            .'/shop-'.$run->shop_id
            .'/run-'.$run->id.'.md';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);

        return [
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'promotion-run-'.$run->id.'.md',
            'content' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeSnapshot(PromotionSnapshot $snapshot): array
    {
        $content = json_encode($this->snapshotService->document($snapshot), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.promotion.reports_directory', config('feed_mediator.runbooks_directory')), '/')
            .'/shop-'.($snapshot->shop_id ?: 'external')
            .'/snapshot-'.$snapshot->id.'.json';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);

        return [
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'promotion-snapshot-'.$snapshot->id.'.json',
            'content' => $content,
        ];
    }
}
