<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedbackRemediationWorkbenchService;
use Illuminate\Console\Command;

class FeedbackReconcileCommand extends Command
{
    protected $signature = 'feedback:reconcile {feedProfileId : Feed profile ID}';

    protected $description = 'Show remediation queue summary for imported feedback.';

    public function handle(FeedbackRemediationWorkbenchService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $summary = $service->summarize($feedProfile);

        $this->line(json_encode($summary['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
