<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedHypercareDashboardService;
use Illuminate\Console\Command;

class FeedHypercareStatusCommand extends Command
{
    protected $signature = 'feed:hypercare:status {feedProfileId : Feed profile ID}';

    protected $description = 'Show current hypercare status for a feed profile.';

    public function handle(FeedHypercareDashboardService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $summary = $service->summarize($feedProfile);

        $this->line(json_encode([
            'hypercare' => [
                'status' => $summary['hypercare']?->status,
                'started_at' => $summary['hypercare']?->started_at?->toIso8601String(),
                'planned_end_at' => $summary['hypercare']?->planned_end_at?->toIso8601String(),
            ],
            'risk_state' => $summary['risk_state'],
            'blocking_alerts' => $summary['blocking_alerts']->count(),
            'stability' => $summary['stability'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
