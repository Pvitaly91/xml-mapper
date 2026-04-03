<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Models\PromotionRun;
use App\Services\Promotion\PromotionService;
use Illuminate\Console\Command;

class PromotionDryRunCommand extends Command
{
    protected $signature = 'promotion:dry-run {sourceFeedProfileId : Source feed profile ID} {targetFeedProfileId : Target feed profile ID} {--strategy=safe_merge : Promotion strategy}';

    protected $description = 'Run a promotion dry-run between two feed profiles.';

    public function handle(PromotionService $service): int
    {
        $source = FeedProfile::findOrFail((int) $this->argument('sourceFeedProfileId'));
        $target = FeedProfile::findOrFail((int) $this->argument('targetFeedProfileId'));
        $run = $service->dryRunBetweenProfiles(
            $source,
            $target,
            (string) ($this->option('strategy') ?: PromotionRun::STRATEGY_SAFE_MERGE),
            null,
            'staging',
            'Staging',
            'CLI promotion dry-run'
        );

        $this->info('Dry-run status: '.$run->status);
        $this->line('Created: '.data_get($run->summary, 'plan.summary.created', 0));
        $this->line('Updated: '.data_get($run->summary, 'plan.summary.updated', 0));
        $this->line('Skipped: '.data_get($run->summary, 'plan.summary.skipped', 0));
        $this->line('Conflicts: '.data_get($run->summary, 'plan.summary.conflicts', 0));

        return self::SUCCESS;
    }
}
