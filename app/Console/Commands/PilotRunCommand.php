<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Pilot\PilotExecutionService;
use Illuminate\Console\Command;

class PilotRunCommand extends Command
{
    protected $signature = 'pilot:run {feedProfileId : Feed profile ID} {--with-sync : Force source sync} {--with-build : Force candidate rebuild} {--with-publish : Continue through publish} {--with-feedback-fixtures : Import bundled feedback fixtures}';

    protected $description = 'Execute a pilot workflow for the given feed profile.';

    public function handle(PilotExecutionService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $run = $service->run($feedProfile, [
            'with_sync' => (bool) $this->option('with-sync'),
            'with_build' => (bool) $this->option('with-build'),
            'with_publish' => (bool) $this->option('with-publish'),
            'with_feedback_fixtures' => (bool) $this->option('with-feedback-fixtures'),
        ]);

        $this->line(json_encode([
            'pilot_run_id' => $run->id,
            'feed_profile_id' => $run->feed_profile_id,
            'state' => $run->state,
            'current_step' => $run->current_step,
            'next_step' => data_get($run->summary, 'execution.next_step'),
            'blocking_reason' => $run->blocking_reason,
            'readiness' => data_get($run->summary, 'readiness'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $run->state === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
