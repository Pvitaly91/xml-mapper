<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Pilot\PilotExecutionService;
use Illuminate\Console\Command;

class PilotPlanCommand extends Command
{
    protected $signature = 'pilot:plan {feedProfileId : Feed profile ID} {--note= : Operator note}';

    protected $description = 'Plan a persisted pilot execution run for a feed profile.';

    public function handle(PilotExecutionService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $run = $service->plan($feedProfile, [
            'note' => $this->option('note'),
        ]);

        $this->line(json_encode([
            'pilot_run_id' => $run->id,
            'feed_profile_id' => $run->feed_profile_id,
            'state' => $run->state,
            'current_step' => $run->current_step,
            'next_step' => data_get($run->summary, 'execution.next_step'),
            'blocking_reason' => $run->blocking_reason,
            'readiness' => data_get($run->summary, 'readiness.status'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
