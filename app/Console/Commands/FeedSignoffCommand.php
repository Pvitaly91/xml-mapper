<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Services\Feeds\FeedSignoffService;
use Illuminate\Console\Command;

class FeedSignoffCommand extends Command
{
    protected $signature = 'feed:signoff
        {generationId : Feed generation ID}
        {status : Sign-off status}
        {--note= : Optional sign-off note}
        {--reason= : Optional sign-off reason}
        {--reviewer= : Optional reviewer name}';

    protected $description = 'Record a sign-off status for a feed generation.';

    public function handle(FeedSignoffService $signoffService): int
    {
        $generation = FeedGeneration::findOrFail((int) $this->argument('generationId'));
        $signoff = $signoffService->record(
            $generation,
            (string) $this->argument('status'),
            null,
            $this->option('reviewer') !== null ? (string) $this->option('reviewer') : null,
            $this->option('note') !== null ? (string) $this->option('note') : null,
            $this->option('reason') !== null ? (string) $this->option('reason') : null,
        );

        $this->info('Sign-off recorded with status '.$signoff->status.'.');

        return self::SUCCESS;
    }
}
