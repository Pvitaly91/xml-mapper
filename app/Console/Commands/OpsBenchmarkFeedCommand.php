<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Ops\BenchmarkService;
use Illuminate\Console\Command;

class OpsBenchmarkFeedCommand extends Command
{
    protected $signature = 'ops:benchmark-feed {feedProfileId : Feed profile ID}';

    protected $description = 'Measure current report/query probes and summarize recent sync/build/publish/smoke timings for a feed profile.';

    public function handle(BenchmarkService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $result = $service->run($feedProfile);

        foreach ($result['summary'] as $key => $value) {
            $this->line($key.': '.$value);
        }

        return self::SUCCESS;
    }
}
