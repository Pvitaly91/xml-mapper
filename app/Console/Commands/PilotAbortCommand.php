<?php

namespace App\Console\Commands;

use App\Models\PilotRun;
use App\Services\Pilot\PilotExecutionService;
use Illuminate\Console\Command;

class PilotAbortCommand extends Command
{
    protected $signature = 'pilot:abort {pilotRunId : Pilot run ID} {--reason= : Abort reason}';

    protected $description = 'Abort an in-flight pilot run and preserve evidence.';

    public function handle(PilotExecutionService $service): int
    {
        $reason = (string) $this->option('reason');

        if ($reason === '') {
            $this->error('Abort reason is required.');

            return self::FAILURE;
        }

        $pilotRun = PilotRun::findOrFail((int) $this->argument('pilotRunId'));
        $run = $service->abort($pilotRun, $reason);

        $this->line(json_encode([
            'pilot_run_id' => $run->id,
            'state' => $run->state,
            'blocking_reason' => $run->blocking_reason,
            'abort' => data_get($run->summary, 'abort'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
