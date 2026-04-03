<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Promotion\PromotionService;
use Illuminate\Console\Command;

class PromotionDiffCommand extends Command
{
    protected $signature = 'promotion:diff {sourceFeedProfileId : Source feed profile ID} {targetFeedProfileId : Target feed profile ID} {--source-env=staging : Source environment class} {--target-env=production : Target environment class label}';

    protected $description = 'Compare a source feed profile snapshot against a target feed profile.';

    public function handle(PromotionService $service): int
    {
        $source = FeedProfile::findOrFail((int) $this->argument('sourceFeedProfileId'));
        $target = FeedProfile::findOrFail((int) $this->argument('targetFeedProfileId'));
        $run = $service->compareBetweenProfiles(
            $source,
            $target,
            null,
            (string) $this->option('source-env'),
            ucfirst((string) $this->option('source-env')),
            'CLI drift compare'
        );

        if ($this->option('target-env')) {
            $run->forceFill(['target_environment' => (string) $this->option('target-env')])->save();
        }

        $this->info('Promotion drift status: '.data_get($run->summary, 'drift.status', $run->status));
        $this->line('Run #'.$run->id);

        return self::SUCCESS;
    }
}
