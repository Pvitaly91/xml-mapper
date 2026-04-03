<?php

namespace App\Console\Commands;

use App\Models\PilotRun;
use App\Services\Pilot\PilotEvidencePackService;
use Illuminate\Console\Command;

class PilotEvidenceCommand extends Command
{
    protected $signature = 'pilot:evidence {pilotRunId : Pilot run ID}';

    protected $description = 'Generate a downloadable evidence pack for a pilot run.';

    public function handle(PilotEvidencePackService $service): int
    {
        $pilotRun = PilotRun::findOrFail((int) $this->argument('pilotRunId'));
        $bundle = $service->generate($pilotRun);

        $this->line(json_encode([
            'pilot_run_id' => $pilotRun->id,
            'state' => $pilotRun->state,
            'path' => $bundle['path'],
            'absolute_path' => $bundle['absolute_path'],
            'filename' => $bundle['filename'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
