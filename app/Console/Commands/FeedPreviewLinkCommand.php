<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Services\Feeds\FeedPreviewLinkService;
use Illuminate\Console\Command;

class FeedPreviewLinkCommand extends Command
{
    protected $signature = 'feed:preview-link
        {generationId : Feed generation ID}
        {--ttl=1440 : Preview link TTL in minutes}
        {--reason= : Optional note for the preview link}';

    protected $description = 'Generate a signed preview URL for a candidate generation.';

    public function handle(FeedPreviewLinkService $previewLinkService): int
    {
        $generation = FeedGeneration::findOrFail((int) $this->argument('generationId'));
        $previewLink = $previewLinkService->create(
            $generation,
            (int) $this->option('ttl'),
            null,
            $this->option('reason') !== null ? (string) $this->option('reason') : null
        );

        $this->line($previewLinkService->urlFor($previewLink));
        $this->info('Preview link expires at '.$previewLink->expires_at?->toDateTimeString().'.');

        return self::SUCCESS;
    }
}
