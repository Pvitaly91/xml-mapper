<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Ops\SecretsRotationService;

class SecretRotationActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly SecretsRotationService $rotationService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $sourceConnection = SourceConnection::query()->findOrFail((int) $payload['source_connection_id']);
        $run = $this->rotationService->record(
            (string) $payload['target'],
            $sourceConnection,
            null,
            $actor,
            (string) ($payload['note'] ?? '')
        );

        return [
            'ops_run_id' => $run->id,
            'source_connection_id' => $sourceConnection->id,
        ];
    }
}
