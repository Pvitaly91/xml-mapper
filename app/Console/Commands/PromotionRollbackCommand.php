<?php

namespace App\Console\Commands;

use App\Models\PromotionRun;
use App\Services\Promotion\PromotionService;
use Illuminate\Console\Command;

class PromotionRollbackCommand extends Command
{
    protected $signature = 'promotion:rollback {promotionRunId : Promotion run ID} {--reason= : Rollback reason}';

    protected $description = 'Rollback a promotion apply run where config-level rollback is safe.';

    public function handle(PromotionService $service): int
    {
        $run = PromotionRun::with(['targetFeedProfile', 'targetSnapshot', 'resultSnapshot'])->findOrFail((int) $this->argument('promotionRunId'));
        $rollback = $service->rollback(
            $run,
            null,
            $this->option('reason') !== null ? (string) $this->option('reason') : 'CLI promotion rollback'
        );

        $this->info('Rollback status: '.$rollback->status);
        $this->line('Run #'.$rollback->id);

        return $rollback->status === PromotionRun::STATUS_SUCCEEDED
            ? self::SUCCESS
            : self::FAILURE;
    }
}
