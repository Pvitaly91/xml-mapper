<?php

namespace App\Services\Pilot;

use App\Models\PilotRun;
use App\Models\PilotRunEvent;
use Illuminate\Support\Facades\Storage;

class PilotReportService
{
    public function __construct(
        private readonly PilotEvidencePackService $evidencePackService,
    ) {}

    /**
     * @return array{type:string,path:string,absolute_path:string,filename:string,content:string}
     */
    public function generate(PilotRun $pilotRun, string $type): array
    {
        $payload = $this->evidencePackService->buildPayload($pilotRun->fresh());

        return match ($type) {
            'summary' => $this->store($pilotRun, 'summary.json', json_encode($payload['execution_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $type),
            'blockers' => $this->store($pilotRun, 'blockers.csv', $this->blockersCsv($payload), $type),
            'execution-log' => $this->store($pilotRun, 'execution-log.csv', $this->executionLogCsv($pilotRun), $type),
            'readiness' => $this->store($pilotRun, 'readiness.json', json_encode($payload['readiness'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $type),
            default => $this->store($pilotRun, 'summary.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $type),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function blockersCsv(array $payload): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['code', 'message', 'next_steps']);
        fputcsv($handle, [
            data_get($payload, 'execution_summary.state'),
            data_get($payload, 'execution_summary.blocking_reason'),
            implode(' | ', (array) data_get($payload, 'readiness.blocking_reasons', [])),
        ]);
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function executionLogCsv(PilotRun $pilotRun): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['occurred_at', 'event_type', 'status', 'step', 'from_state', 'to_state', 'title', 'message', 'user']);

        foreach ($pilotRun->events()->with('user')->orderBy('occurred_at')->get() as $event) {
            /** @var PilotRunEvent $event */
            fputcsv($handle, [
                $event->occurred_at?->toIso8601String(),
                $event->event_type,
                $event->status,
                $event->step,
                $event->from_state,
                $event->to_state,
                $event->title,
                $event->message,
                $event->user?->email,
            ]);
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @return array{type:string,path:string,absolute_path:string,filename:string,content:string}
     */
    private function store(PilotRun $pilotRun, string $filename, string $content, string $type): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.pilot.reports_directory', 'feeds/runbooks/pilot/reports'), '/')
            .'/pilot-run-'.$pilotRun->id.'/'.$filename;
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $disk->put($relativePath, $content);

        return [
            'type' => $type,
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'pilot-run-'.$pilotRun->id.'-'.$filename,
            'content' => $content,
        ];
    }
}
