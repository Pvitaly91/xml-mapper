<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedHypercareReportService;
use Illuminate\Console\Command;

class OpsHandoffCommand extends Command
{
    protected $signature = 'ops:handoff {feedProfileId : Feed profile ID}';

    protected $description = 'Generate a shift handoff report for an active hypercare window.';

    public function handle(FeedHypercareReportService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $report = $service->shiftHandoff($feedProfile);

        $this->line($report['absolute_path']);
        $this->info('Shift handoff generated.');

        return self::SUCCESS;
    }
}
