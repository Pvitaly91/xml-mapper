<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedSmokeCheckService;
use Illuminate\Console\Command;

class FeedSmokeCheckCommand extends Command
{
    protected $signature = 'feed:smoke-check
        {feedProfileId? : Feed profile ID}
        {generationId? : Feed generation ID}
        {--latest-published : Use the latest published generation for the selected profile}
        {--reason= : Optional reason for manual smoke check}';

    protected $description = 'Run smoke checks against the published feed URL.';

    public function handle(FeedSmokeCheckService $smokeCheckService): int
    {
        [$feedProfile, $generation] = $this->resolveTargets();
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;

        $smokeCheck = $smokeCheckService->run(
            $feedProfile,
            $generation,
            FeedGenerationSmokeCheck::TRIGGER_COMMAND,
            null,
            $reason
        );

        $this->info('Smoke check finished with status '.$smokeCheck->status.'.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: FeedProfile, 1: FeedGeneration}
     */
    private function resolveTargets(): array
    {
        $feedProfileId = $this->argument('feedProfileId');
        $generationId = $this->argument('generationId');

        if ($generationId !== null) {
            $generation = FeedGeneration::findOrFail((int) $generationId);
            $feedProfile = $feedProfileId !== null
                ? FeedProfile::findOrFail((int) $feedProfileId)
                : $generation->feedProfile()->firstOrFail();

            if ($generation->feed_profile_id !== $feedProfile->id) {
                throw new \RuntimeException('Selected generation does not belong to the selected feed profile.');
            }

            return [$feedProfile, $generation];
        }

        if ($feedProfileId === null) {
            throw new \RuntimeException('Provide feedProfileId or generationId.');
        }

        $feedProfile = FeedProfile::findOrFail((int) $feedProfileId);
        $generation = $this->option('latest-published')
            ? $feedProfile->publishedGeneration
            : ($feedProfile->publishedGeneration ?? $feedProfile->latestGeneration);

        if (! $generation instanceof FeedGeneration) {
            throw new \RuntimeException('No generation available for smoke check.');
        }

        return [$feedProfile, $generation];
    }
}
