<?php

namespace App\Services\Launch;

use App\Models\MerchantLaunch;
use App\Models\MerchantLaunchDefect;
use App\Models\MerchantLaunchObservation;
use Illuminate\Support\Facades\Storage;

class MerchantLaunchReportService
{
    public function __construct(
        private readonly MerchantLaunchService $launchService,
    ) {}

    /**
     * @return array{type:string,path:string,absolute_path:string,filename:string,content:string}
     */
    public function generate(MerchantLaunch $launch, string $type): array
    {
        $detail = $this->launchService->snapshot($launch);

        return match ($type) {
            'summary' => $this->store($launch, 'summary.json', json_encode([
                'launch' => $detail['launch']->toArray(),
                'baseline' => $detail['baseline'],
                'handover' => $detail['handover'],
                'check' => $detail['check'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $type),
            'observations' => $this->store($launch, 'observations.csv', $this->observationsCsv($launch), $type),
            'defects' => $this->store($launch, 'defects.csv', $this->defectsCsv($launch), $type),
            'closeout' => $this->store($launch, 'closeout.md', $this->closeoutMarkdown($detail), $type),
            default => $this->store($launch, 'summary.json', json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $type),
        };
    }

    private function observationsCsv(MerchantLaunch $launch): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['observed_at', 'type', 'severity', 'source', 'note', 'user']);

        foreach ($launch->observations()->with('user')->orderBy('observed_at')->get() as $observation) {
            /** @var MerchantLaunchObservation $observation */
            fputcsv($handle, [
                $observation->observed_at?->toIso8601String(),
                $observation->type,
                $observation->severity,
                $observation->source,
                $observation->note,
                $observation->user?->email,
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function defectsCsv(MerchantLaunch $launch): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['opened_at', 'type', 'severity', 'status', 'title', 'note', 'user']);

        foreach ($launch->defects()->with('user')->orderBy('opened_at')->get() as $defect) {
            /** @var MerchantLaunchDefect $defect */
            fputcsv($handle, [
                $defect->opened_at?->toIso8601String(),
                $defect->type,
                $defect->severity,
                $defect->status,
                $defect->title,
                $defect->note,
                $defect->user?->email,
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function closeoutMarkdown(array $detail): string
    {
        /** @var MerchantLaunch $launch */
        $launch = $detail['launch'];
        $lines = [
            '# Live Merchant Launch Closeout',
            '',
            'Launch: #'.$launch->id,
            'Feed profile: '.$launch->feedProfile->name.' ('.$launch->feedProfile->code.')',
            'State: '.$launch->state,
            'Handover: '.$launch->handover_state,
            'Outcome: '.($launch->outcome ?: 'n/a'),
            '',
            '## What Happened',
        ];

        foreach ($detail['history']->take(20) as $event) {
            $lines[] = '- ['.optional($event->occurred_at)->toIso8601String().'] '.$event->action.' - '.($event->reason ?: 'n/a');
        }

        $lines[] = '';
        $lines[] = '## Issues Found';

        foreach ($launch->defects()->latest('opened_at')->get() as $defect) {
            $lines[] = '- ['.$defect->severity.']['.$defect->status.'] '.$defect->title;
        }

        if ($launch->defects()->count() === 0) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Open Risks';

        foreach ((array) ($detail['blockers'] ?? []) as $blocker) {
            $lines[] = '- ['.$blocker['severity'].'] '.$blocker['message'];
        }

        if (($detail['blockers'] ?? []) === []) {
            $lines[] = '- No open blockers remain.';
        }

        $lines[] = '';
        $lines[] = '## Recommended Follow-up Actions';

        foreach ((array) ($detail['next_actions'] ?? []) as $step) {
            $lines[] = '- '.$step;
        }

        if (($detail['next_actions'] ?? []) === []) {
            $lines[] = '- Continue normal production operations.';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return array{type:string,path:string,absolute_path:string,filename:string,content:string}
     */
    private function store(MerchantLaunch $launch, string $filename, string $content, string $type): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.launch.reports_directory', 'feeds/runbooks/launch/reports'), '/')
            .'/launch-'.$launch->id.'/'.$filename;
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);

        return [
            'type' => $type,
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'launch-'.$launch->id.'-'.$filename,
            'content' => $content,
        ];
    }
}
