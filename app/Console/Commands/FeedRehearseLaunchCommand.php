<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedRehearsalService;
use Illuminate\Console\Command;

class FeedRehearseLaunchCommand extends Command
{
    protected $signature = 'feed:rehearse-launch
        {feedProfileId : Feed profile ID}
        {--with-sync : Run source sync as part of rehearsal}
        {--with-build : Force a fresh candidate build}
        {--with-preview : Generate a preview/canary artifact}
        {--with-smoke : Run rehearsal smoke and first-pull verification}
        {--with-rollback-check : Run rollback rehearsal against the currently published generation}';

    protected $description = 'Run a staging rehearsal for first merchant launch readiness.';

    public function handle(FeedRehearsalService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $result = $service->run($feedProfile, [
            'with_sync' => (bool) $this->option('with-sync'),
            'with_build' => (bool) $this->option('with-build'),
            'with_preview' => (bool) $this->option('with-preview'),
            'with_smoke' => (bool) $this->option('with-smoke'),
            'with_rollback_check' => (bool) $this->option('with-rollback-check'),
        ]);

        $this->info('Rehearsal status: '.$result['status']);

        foreach ($result['steps'] as $step) {
            $this->line(sprintf('- %s: %s', $step['key'], $step['status']));
        }

        foreach ($result['blocking_issues'] as $issue) {
            $this->warn('Blocking: '.$issue);
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn('Warning: '.$warning);
        }

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
