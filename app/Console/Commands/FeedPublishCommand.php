<?php

namespace App\Console\Commands;

use App\Jobs\PublishFeedJob;
use App\Models\FeedProfile;
use Illuminate\Console\Command;

class FeedPublishCommand extends Command
{
    protected $signature = 'feed:publish {feedProfileId : Feed profile ID} {--generation= : Optional generation ID} {--queue : Dispatch to queue instead of sync execution}';

    protected $description = 'Publish built XML feed into public cached path.';

    public function handle(): int
    {
        $profile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $generationId = $this->option('generation') ? (int) $this->option('generation') : null;

        if ($this->option('queue')) {
            PublishFeedJob::dispatch($profile->id, $generationId);
            $this->line("Queued publish for feed profile #{$profile->id}.");
        } else {
            PublishFeedJob::dispatchSync($profile->id, $generationId);
            $this->line("Published feed profile #{$profile->id}.");
        }

        return self::SUCCESS;
    }
}
