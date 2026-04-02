<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Services\Feeds\FeedReleaseService;
use Illuminate\Console\Command;

class FeedApproveCommand extends Command
{
    protected $signature = 'feed:approve {generationId : Feed generation ID} {--reason= : Optional approval reason}';

    protected $description = 'Approve a built feed generation for release.';

    public function handle(FeedReleaseService $releaseService): int
    {
        $generation = FeedGeneration::findOrFail((int) $this->argument('generationId'));
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;

        if ($generation->release_status !== FeedGeneration::RELEASE_STATUS_CANDIDATE) {
            $generation = $releaseService->markCandidate($generation, null, $reason);
        }

        $generation = $releaseService->approve($generation, null, $reason);

        $this->info('Generation #'.$generation->id.' approved.');

        return self::SUCCESS;
    }
}
