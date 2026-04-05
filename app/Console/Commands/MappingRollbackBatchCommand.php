<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Console\Commands\Concerns\ResolvesMappingActor;
use App\Models\MappingBatch;
use App\Services\Mappings\Automation\MappingBatchService;
use Illuminate\Console\Command;

class MappingRollbackBatchCommand extends Command
{
    use ResolvesGovernanceInput;
    use ResolvesMappingActor;

    protected $signature = 'mapping:rollback-batch {batchId} {--reason=} {--by=}';

    protected $description = 'Rollback the last applied mapping batch where safe.';

    public function handle(MappingBatchService $batchService): int
    {
        $batch = MappingBatch::query()->with('requestedBy', 'feedProfile.user', 'feedProfile.shop')->findOrFail((int) $this->argument('batchId'));
        $actor = $this->resolveActorForBatch($batch, $this->option('by'));
        $batch = $batchService->rollback($batch, $actor, $this->option('reason'));

        $this->info(sprintf('Batch #%d rolled back.', $batch->id));

        return self::SUCCESS;
    }
}
