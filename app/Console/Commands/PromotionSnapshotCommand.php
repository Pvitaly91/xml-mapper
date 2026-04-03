<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Promotion\PromotionReportService;
use App\Services\Promotion\PromotionService;
use Illuminate\Console\Command;

class PromotionSnapshotCommand extends Command
{
    protected $signature = 'promotion:snapshot {feedProfileId : Feed profile ID}';

    protected $description = 'Generate a promotion snapshot for a feed profile.';

    public function handle(PromotionService $service, PromotionReportService $reportService): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $environment = (string) ($this->option('env') ?: 'staging');
        $snapshot = $service->generateSnapshot(
            $feedProfile,
            null,
            $environment,
            ucfirst($environment)
        );
        $report = $reportService->storeSnapshot($snapshot);

        $this->info('Snapshot generated.');
        $this->line('Checksum: '.$snapshot->checksum);
        $this->line($report['absolute_path']);

        return self::SUCCESS;
    }
}
