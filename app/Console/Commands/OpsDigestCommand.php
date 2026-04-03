<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedHypercareReportService;
use Illuminate\Console\Command;

class OpsDigestCommand extends Command
{
    protected $signature = 'ops:digest {feedProfileId : Feed profile ID} {--date= : Digest date (Y-m-d)}';

    protected $description = 'Generate a daily hypercare digest report.';

    public function handle(FeedHypercareReportService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $report = $service->dailyDigest(
            $feedProfile,
            $this->option('date') !== null ? (string) $this->option('date') : null
        );

        $this->line($report['absolute_path']);
        $this->info('Daily digest generated.');

        return self::SUCCESS;
    }
}
