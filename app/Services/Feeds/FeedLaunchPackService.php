<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\OpsRun;
use App\Models\User;
use App\Services\Ops\OpsRunService;
use App\Services\Ops\RestoreDrillService;
use App\Services\Ops\SloSummaryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class FeedLaunchPackService
{
    public function __construct(
        private readonly FeedAcceptanceService $acceptanceService,
        private readonly FeedOperationsService $operationsService,
        private readonly FeedReconciliationService $reconciliationService,
        private readonly FeedRehearsalService $rehearsalService,
        private readonly FeedReleaseNotesService $notesService,
        private readonly RestoreDrillService $restoreDrillService,
        private readonly SloSummaryService $sloSummaryService,
        private readonly OpsRunService $opsRunService,
    ) {}

    /**
     * @return array{path:string,absolute_path:string,filename:string,content:string}
     */
    public function generate(FeedProfile $feedProfile, ?FeedGeneration $generation = null, ?User $user = null): array
    {
        $generation ??= $feedProfile->latestGeneration;
        $acceptance = $this->acceptanceService->summarize($feedProfile, $generation);
        $operations = $this->operationsService->summarize($feedProfile);
        $rehearsal = $this->rehearsalService->summarize($feedProfile);
        $restoreDrill = $this->restoreDrillService->summarize($feedProfile);
        $reconciliation = $this->reconciliationService->summarize($feedProfile);
        $slo = $this->sloSummaryService->summarize($feedProfile->shop, $feedProfile);
        $content = $this->render($feedProfile, $generation, $acceptance, $operations, $rehearsal, $restoreDrill, $reconciliation, $slo);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.runbooks_directory'), '/')
            .'/shop-'.$feedProfile->shop_id
            .'/feed-'.$feedProfile->id
            .'/launch-pack-'.($generation?->id ?? 'latest').'-'.now()->format('YmdHis').'.md';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);
        $run = $this->opsRunService->start(OpsRun::TYPE_LAUNCH_PACK, $feedProfile->shop, $feedProfile, $user);
        $this->opsRunService->finish($run, OpsRun::STATUS_SUCCEEDED, [
            'generation_id' => $generation?->id,
        ], [], $relativePath, strlen($content));

        return [
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'launch-pack-feed-profile-'.$feedProfile->id.'.md',
            'content' => $content,
        ];
    }

    private function render(
        FeedProfile $feedProfile,
        ?FeedGeneration $generation,
        array $acceptance,
        array $operations,
        array $rehearsal,
        array $restoreDrill,
        array $reconciliation,
        array $slo
    ): string {
        $notes = $generation ? $this->notesService->importantNotes($generation) : collect();
        $window24 = $slo['windows']['24h'] ?? null;
        $previewLinks = $acceptance['preview_links'] ?? collect();

        $lines = [
            '# First Merchant Launch Pack',
            '',
            'Shop: '.($feedProfile->shop?->name ?: 'n/a'),
            'Feed profile: '.$feedProfile->name.' ('.$feedProfile->code.')',
            'Generation: '.($generation?->id ? '#'.$generation->id : 'n/a'),
            'Generated at: '.now()->toDateTimeString(),
            '',
            '## Shop summary',
            '- Source connection: '.($feedProfile->sourceConnection?->name ?: 'n/a'),
            '- Source status: '.($feedProfile->sourceConnection?->last_connection_check_status ?: 'n/a'),
            '- Dictionaries imported: '.(($acceptance['pilot_readiness']['dictionaries_imported']['ok'] ?? false) ? 'yes' : 'no'),
            '- Unresolved mappings: '.($acceptance['unresolved_mappings_count'] ?? 0),
            '',
            '## Candidate generation summary',
            '- Release status: '.($generation?->release_status ?: 'n/a'),
            '- Ready / invalid / excluded: '
                .(($acceptance['pilot_readiness']['generation_summary']['ready'] ?? 0)).' / '
                .(($acceptance['pilot_readiness']['generation_summary']['invalid_total'] ?? 0)).' / '
                .(($acceptance['pilot_readiness']['generation_summary']['excluded'] ?? 0)),
            '- Readiness: '.($acceptance['release_readiness']['status'] ?? 'n/a'),
            '- Sign-off: '.($acceptance['signoff']['current']?->status ?: 'n/a'),
            '- Publish window: '.(($acceptance['publish_window']['allowed_now'] ?? false) ? 'open' : 'closed'),
            '- Freeze mode: '.(($acceptance['publish_window']['freeze_active'] ?? false) ? 'active' : 'inactive'),
            '',
            '## Preview / QA',
            '- Preview links: '.($previewLinks instanceof Collection ? $previewLinks->count() : 0),
            '- Latest rehearsal: '.($rehearsal['latest']?->status ?: 'n/a'),
            '- QA bundle reference: generation #'.($generation?->id ?? 'n/a').' via release center',
            '',
            '## Cutover / rollback / first-pull plan',
            '- Cutover status: '.($operations['cutover']['cutover']?->status ?: 'n/a'),
            '- Next allowed window: '.($operations['publish_window']['next_allowed_at'] ?? 'n/a'),
            '- Rollback target available: '.($feedProfile->publishedGeneration?->id ? 'yes (#'.$feedProfile->publishedGeneration->id.')' : 'no'),
            '- First-pull verification latest: '.($operations['first_pull']['latest']?->status ?: 'n/a'),
            '- Feedback import plan: import CSV/JSON into the feedback workflow after first external review.',
            '',
            '## Reconciliation / reliability',
            '- Source variants: '.($reconciliation['summary']['source_variants_total'] ?? 0),
            '- Ready feed items: '.($reconciliation['summary']['ready_total'] ?? 0),
            '- Published latest generation: '.($reconciliation['summary']['published_total'] ?? 0),
            '- Latest restore drill: '.($restoreDrill['latest']?->status ?: 'n/a'),
            '- SLO 24h status: '.($window24['status'] ?? 'n/a'),
            '',
            '## Operator notes',
        ];

        if ($notes->isEmpty()) {
            $lines[] = '- No important notes recorded.';
        } else {
            foreach ($notes as $note) {
                $lines[] = '- '.($note->meta['body'] ?? $note->reason);
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
