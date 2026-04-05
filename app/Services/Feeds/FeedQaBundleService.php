<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class FeedQaBundleService
{
    public function __construct(
        private readonly FeedReleaseReportService $reportService,
        private readonly FeedSignoffService $signoffService,
        private readonly FeedReleaseNotesService $notesService,
        private readonly FeedReleaseAuditService $auditService,
        private readonly PilotNotificationService $notificationService,
    ) {}

    /**
     * @return array{path:string,absolute_path:string,filename:string,summary:array<string,mixed>}
     */
    public function generate(FeedGeneration $generation, ?User $user = null, ?string $reason = null): array
    {
        $feedProfile = $generation->feedProfile()->with(['shop'])->firstOrFail();
        $disk = Storage::disk(config('feed_mediator.storage_disk'));

        if (blank($generation->file_path) || ! $disk->exists($generation->file_path)) {
            throw new RuntimeException('Generation XML file is missing.');
        }

        $relativePath = trim(config('feed_mediator.builds_directory'), '/')
            .'/shop-'.$feedProfile->shop_id
            .'/feed-'.$feedProfile->id
            .'/qa-bundle-generation-'.$generation->id.'-'.now()->format('YmdHis').'.zip';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $summary = [
            'shop' => [
                'id' => $feedProfile->shop_id,
                'name' => $feedProfile->shop?->name,
            ],
            'feed_profile' => [
                'id' => $feedProfile->id,
                'name' => $feedProfile->name,
                'code' => $feedProfile->code,
            ],
            'generation' => [
                'id' => $generation->id,
                'status' => $generation->status,
                'release_status' => $generation->release_status,
                'built_at' => $generation->built_at?->toIso8601String(),
            ],
            'counts' => [
                'ready' => (int) ($generation->meta['summary']['ready'] ?? $generation->valid_items_total),
                'invalid' => (int) ($generation->meta['summary']['invalid_total'] ?? $generation->invalid_items_total),
                'excluded' => (int) ($generation->meta['summary']['excluded'] ?? 0),
            ],
            'category_count' => (int) ($generation->smokeChecks()->latest('checked_at')->first()?->categories_total ?? 0),
            'offers_count' => (int) ($generation->meta['summary']['ready'] ?? $generation->valid_items_total),
            'checksum' => $generation->checksum,
            'signoff' => $this->signoffService->evaluate($feedProfile, $generation),
        ];

        $diffReport = $this->reportService->generationDiffReport($feedProfile, $generation);
        $readinessReport = $this->reportService->readinessReport($feedProfile, $generation);
        $functionalXmlReport = $this->reportService->functionalXmlReport($feedProfile, $generation);
        $smokeCheckSummary = $this->reportService->smokeCheckReport($generation);
        $releaseNotes = $this->releaseNotes($generation);
        $invalidItemsTemp = tempnam(sys_get_temp_dir(), 'qa-invalid-');

        if ($invalidItemsTemp === false) {
            throw new RuntimeException('Unable to allocate temporary QA bundle file.');
        }

        $invalidItemsHandle = fopen($invalidItemsTemp, 'w+');

        if ($invalidItemsHandle === false) {
            throw new RuntimeException('Unable to open temporary QA bundle CSV file.');
        }

        $this->reportService->writeInvalidItemsCsv($invalidItemsHandle, $feedProfile, $generation);
        fclose($invalidItemsHandle);

        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create QA bundle ZIP archive.');
        }

        $zip->addFile($disk->path($generation->file_path), 'candidate.xml');
        $zip->addFromString('summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFile($invalidItemsTemp, 'invalid-items.csv');
        $zip->addFromString('generation-diff.json', json_encode($diffReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('readiness.json', json_encode($readinessReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('functional-xml-report.json', json_encode($functionalXmlReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('functional-included-items.csv', $this->reportService->functionalXmlCsv($feedProfile, $generation, 'included'));
        $zip->addFromString('functional-excluded-items.csv', $this->reportService->functionalXmlCsv($feedProfile, $generation, 'excluded'));
        $zip->addFromString('functional-issues.csv', $this->reportService->functionalXmlCsv($feedProfile, $generation, 'issues'));
        $zip->addFromString('functional-blockers.csv', $this->reportService->functionalXmlCsv($feedProfile, $generation, 'blockers'));
        $zip->addFromString('smoke-check-summary.json', json_encode($smokeCheckSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('release-notes.txt', $releaseNotes);
        $zip->close();
        @unlink($invalidItemsTemp);

        $this->auditService->record(
            $feedProfile,
            $generation,
            'qa_bundle_generated',
            $user,
            $reason,
            ['bundle_path' => $relativePath]
        );

        $this->notificationService->notifyFeedProfileAdmins(
            $feedProfile,
            'feed.qa_bundle_generated',
            'Candidate QA bundle generated',
            'A QA bundle is ready for download and review.',
            [
                'generation_id' => $generation->id,
                'bundle_path' => $relativePath,
            ],
            'info',
            $generation
        );

        return [
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'generation-'.$generation->id.'-qa-bundle.zip',
            'summary' => $summary,
        ];
    }

    private function releaseNotes(FeedGeneration $generation): string
    {
        $importantNotes = $this->notesService->importantNotes($generation);
        $signoff = $this->signoffService->current($generation);

        $lines = [
            'Release notes',
            '-------------',
            'Generation: #'.$generation->id,
            'Release status: '.$generation->release_status,
            'Built at: '.($generation->built_at?->toDateTimeString() ?: 'n/a'),
            'Ready items: '.(string) ($generation->meta['summary']['ready'] ?? $generation->valid_items_total),
            'Invalid items: '.(string) ($generation->meta['summary']['invalid_total'] ?? $generation->invalid_items_total),
            'Excluded items: '.(string) ($generation->meta['summary']['excluded'] ?? 0),
            'Checksum: '.($generation->checksum ?: 'n/a'),
            '',
            'Current sign-off: '.($signoff?->status ?: 'not recorded'),
        ];

        if ($signoff?->reviewer_name) {
            $lines[] = 'Reviewer: '.$signoff->reviewer_name;
        }

        if ($signoff?->note) {
            $lines[] = 'Sign-off note: '.$signoff->note;
        }

        if ($importantNotes->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Important notes:';

            foreach ($importantNotes as $note) {
                $lines[] = '- '.($note->meta['body'] ?? $note->reason);
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
