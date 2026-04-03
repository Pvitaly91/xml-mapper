<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use Illuminate\Support\Facades\Storage;

class FeedRunbookService
{
    public function __construct(
        private readonly FeedAcceptanceService $acceptanceService,
        private readonly FeedCutoverService $cutoverService,
    ) {}

    /**
     * @return array{path:string,absolute_path:string,filename:string,content:string,checklist:array<int, array<string, mixed>>}
     */
    public function generate(FeedProfile $feedProfile, ?FeedGeneration $generation = null): array
    {
        $summary = $this->acceptanceService->summarize($feedProfile, $generation);
        $cutover = $this->cutoverService->summarize($feedProfile, $generation);
        $generation ??= $summary['generation'];
        $checklist = $this->checklist($summary, $cutover);
        $content = $this->render($feedProfile, $generation, $checklist, $cutover);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.runbooks_directory'), '/')
            .'/shop-'.$feedProfile->shop_id
            .'/feed-'.$feedProfile->id
            .'/runbook-'.($generation?->id ?? 'latest').'-'.now()->format('YmdHis').'.md';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);

        if ($cutover['cutover']) {
            $cutoverModel = $cutover['cutover'];
            $cutoverModel->forceFill([
                'meta' => array_merge($cutoverModel->meta ?? [], [
                    'runbook_snapshot' => [
                        'generated_at' => now()->toIso8601String(),
                        'generation_id' => $generation?->id,
                        'checklist' => $checklist,
                    ],
                ]),
            ])->save();
        }

        return [
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'feed-profile-'.$feedProfile->id.'-runbook.md',
            'content' => $content,
            'checklist' => $checklist,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $cutover
     * @return array<int, array<string, mixed>>
     */
    private function checklist(array $summary, array $cutover): array
    {
        return [
            ['label' => 'Source connection verified', 'ok' => (bool) ($summary['release_readiness']['checks']['source_healthy']['ok'] ?? false)],
            ['label' => 'Dictionaries imported', 'ok' => (bool) ($summary['pilot_readiness']['dictionaries_imported']['ok'] ?? false)],
            ['label' => 'Mappings reviewed', 'ok' => (bool) ($summary['release_readiness']['checks']['mappings_complete']['ok'] ?? false)],
            ['label' => 'Promotion parity reviewed', 'ok' => in_array(($summary['promotion']['status'] ?? 'unknown'), ['in_sync', 'unknown'], true)],
            ['label' => 'Candidate built', 'ok' => (bool) ($summary['release_readiness']['checks']['generation_built']['ok'] ?? false)],
            ['label' => 'QA bundle generated', 'ok' => $summary['generation'] !== null],
            ['label' => 'Sign-off complete', 'ok' => (bool) ($summary['signoff']['allowed'] ?? false)],
            ['label' => 'Publish window valid', 'ok' => (bool) ($summary['publish_window']['allowed_now'] ?? false)],
            ['label' => 'Freeze off or override acknowledged', 'ok' => ! (bool) ($summary['publish_window']['freeze_active'] ?? false)],
            ['label' => 'Publish executed', 'ok' => ($cutover['cutover']?->actual_published_at !== null)],
            ['label' => 'First-pull verification executed', 'ok' => ($cutover['cutover']?->first_verified_at !== null)],
            ['label' => 'Feedback import done', 'ok' => (($cutover['feedback_summary']['imports_total'] ?? 0) > 0)],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $cutover
     */
    private function render(FeedProfile $feedProfile, ?FeedGeneration $generation, array $checklist, array $cutover): string
    {
        $lines = [
            '# Merchant Cutover Runbook',
            '',
            'Shop: '.$feedProfile->shop?->name,
            'Feed profile: '.$feedProfile->name.' ('.$feedProfile->code.')',
            'Generation: '.($generation?->id ? '#'.$generation->id : 'n/a'),
            'Generated at: '.now()->toDateTimeString(),
            'Current cutover status: '.($cutover['cutover']?->status ?? 'n/a'),
            '',
            '## Checklist',
        ];

        foreach ($checklist as $item) {
            $lines[] = '- ['.($item['ok'] ? 'x' : ' ').'] '.$item['label'];
        }

        $lines[] = '';
        $lines[] = '## Blocking Issues';

        foreach (($cutover['blocking_issues'] ?? []) !== [] ? $cutover['blocking_issues'] : ['None.'] as $issue) {
            $lines[] = '- '.$issue;
        }

        $lines[] = '';
        $lines[] = '## Warnings';

        foreach (($cutover['warnings'] ?? []) !== [] ? $cutover['warnings'] : ['None.'] as $warning) {
            $lines[] = '- '.$warning;
        }

        $lines[] = '';
        $lines[] = '## Next Steps';

        foreach (($cutover['next_steps'] ?? []) !== [] ? $cutover['next_steps'] : ['None.'] as $step) {
            $lines[] = '- '.$step;
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
