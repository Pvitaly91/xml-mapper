<?php

namespace App\Console\Commands;

use App\Models\FeedItem;
use App\Models\FeedbackRecord;
use App\Models\GovernanceAudit;
use App\Models\OpsNotificationDelivery;
use App\Models\PerformanceRunStage;
use Illuminate\Console\Command;

class OpsReportHeavyQueriesCommand extends Command
{
    protected $signature = 'ops:report-heavy-queries';

    protected $description = 'Report recent heavy query/report hotspots using persisted performance stages and current table sizes.';

    public function handle(): int
    {
        $topStages = PerformanceRunStage::query()
            ->whereIn('stage', ['reconciliation', 'report_generation', 'feedback_import'])
            ->orderByDesc('duration_ms')
            ->limit(10)
            ->get(['performance_run_id', 'stage', 'duration_ms', 'budget_status', 'created_at']);

        $this->line('Recent heavy performance stages:');

        foreach ($topStages as $stage) {
            $this->line(sprintf(
                '#%d %s duration=%sms budget=%s at=%s',
                $stage->performance_run_id,
                $stage->stage,
                $stage->duration_ms ?? 'n/a',
                $stage->budget_status,
                optional($stage->created_at)->format('Y-m-d H:i:s') ?: 'n/a',
            ));
        }

        $this->line('');
        $this->line('Current table volumes:');
        $this->line('feed_items: '.FeedItem::query()->count());
        $this->line('feedback_records: '.FeedbackRecord::query()->count());
        $this->line('governance_audits: '.GovernanceAudit::query()->count());
        $this->line('notification_deliveries: '.OpsNotificationDelivery::query()->count());

        return self::SUCCESS;
    }
}
