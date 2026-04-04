<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Ops\PruneService;

class PruneActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly PruneService $pruneService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $result = $this->pruneService->run($actor);

        return [
            'summary' => $result['summary'],
        ];
    }
}
