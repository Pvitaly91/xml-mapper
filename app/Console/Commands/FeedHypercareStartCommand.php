<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedHypercareService;
use Illuminate\Console\Command;

class FeedHypercareStartCommand extends Command
{
    protected $signature = 'feed:hypercare:start {feedProfileId : Feed profile ID} {--hours=24 : Hypercare duration in hours} {--note= : Optional operator note}';

    protected $description = 'Start or arm a hypercare window for a feed profile.';

    public function handle(FeedHypercareService $service): int
    {
        $feedProfile = FeedProfile::query()->with(['publishedGeneration', 'latestGeneration', 'currentCutover'])->findOrFail((int) $this->argument('feedProfileId'));
        $window = $service->start(
            $feedProfile,
            max(1, (int) $this->option('hours')),
            $this->option('note') !== null ? (string) $this->option('note') : null
        );

        $this->info('Hypercare status: '.$window->status);

        return self::SUCCESS;
    }
}
