<?php

namespace App\Console\Commands;

use App\Models\PilotRun;
use App\Services\Pilot\PilotExecutionService;
use Illuminate\Console\Command;

class PilotStatusCommand extends Command
{
    protected $signature = 'pilot:status {pilotRunId : Pilot run ID}';

    protected $description = 'Show current pilot run status, blockers, and readiness.';

    public function handle(PilotExecutionService $service): int
    {
        $pilotRun = PilotRun::findOrFail((int) $this->argument('pilotRunId'));
        $run = $service->refreshOperationalState($pilotRun);

        $this->line(json_encode([
            'pilot_run_id' => $run->id,
            'feed_profile_id' => $run->feed_profile_id,
            'state' => $run->state,
            'current_step' => $run->current_step,
            'next_step' => data_get($run->summary, 'execution.next_step'),
            'blocking_reason' => $run->blocking_reason,
            'blocker' => data_get($run->summary, 'blocker'),
            'resume' => data_get($run->summary, 'resume'),
            'readiness' => data_get($run->summary, 'readiness'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $run->state === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
