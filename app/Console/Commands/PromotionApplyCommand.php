<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Models\PromotionRun;
use App\Services\Promotion\PromotionService;
use Illuminate\Console\Command;

class PromotionApplyCommand extends Command
{
    protected $signature = 'promotion:apply {sourceFeedProfileId : Source feed profile ID} {targetFeedProfileId : Target feed profile ID} {--strategy=safe_merge : Promotion strategy} {--reason= : Operator reason}';

    protected $description = 'Apply a promotion snapshot from a source feed profile to a target feed profile.';

    public function handle(PromotionService $service): int
    {
        $source = FeedProfile::findOrFail((int) $this->argument('sourceFeedProfileId'));
        $target = FeedProfile::findOrFail((int) $this->argument('targetFeedProfileId'));
        $run = $service->applyBetweenProfiles(
            $source,
            $target,
            (string) ($this->option('strategy') ?: PromotionRun::STRATEGY_SAFE_MERGE),
            null,
            'staging',
            'Staging',
            $this->option('reason') !== null ? (string) $this->option('reason') : 'CLI promotion apply'
        );

        $this->info('Apply status: '.$run->status);
        $this->line('Run #'.$run->id);
        $this->line('Created: '.data_get($run->summary, 'plan.summary.created', 0));
        $this->line('Updated: '.data_get($run->summary, 'plan.summary.updated', 0));
        $this->line('Skipped: '.data_get($run->summary, 'plan.summary.skipped', 0));

        return $run->status === PromotionRun::STATUS_SUCCEEDED || $run->status === PromotionRun::STATUS_WARNING
            ? self::SUCCESS
            : self::FAILURE;
    }
}
