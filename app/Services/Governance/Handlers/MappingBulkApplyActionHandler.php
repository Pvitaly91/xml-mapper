<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\MappingBatch;
use App\Models\User;
use App\Services\Mappings\Automation\MappingBatchService;

class MappingBulkApplyActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly MappingBatchService $batchService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $batch = MappingBatch::query()->findOrFail((int) $payload['mapping_batch_id']);
        $batch = $this->batchService->executeBatch($batch, $actor ?? $batch->requestedBy);

        return [
            'mapping_batch_id' => $batch->id,
            'status' => $batch->status,
            'summary' => $batch->summary,
        ];
    }
}
