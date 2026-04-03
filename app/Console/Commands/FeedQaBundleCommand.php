<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Services\Feeds\FeedQaBundleService;
use Illuminate\Console\Command;

class FeedQaBundleCommand extends Command
{
    protected $signature = 'feed:qa-bundle
        {generationId : Feed generation ID}
        {--reason= : Optional note for the bundle generation}';

    protected $description = 'Generate a QA bundle ZIP for a feed generation.';

    public function handle(FeedQaBundleService $bundleService): int
    {
        $generation = FeedGeneration::findOrFail((int) $this->argument('generationId'));
        $bundle = $bundleService->generate(
            $generation,
            null,
            $this->option('reason') !== null ? (string) $this->option('reason') : null
        );

        $this->line($bundle['absolute_path']);
        $this->info('QA bundle generated for generation #'.$generation->id.'.');

        return self::SUCCESS;
    }
}
