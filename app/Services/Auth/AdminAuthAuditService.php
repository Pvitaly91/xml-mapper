<?php

namespace App\Services\Auth;

use App\Models\ApprovalRequest;
use App\Models\GovernanceAudit;
use App\Models\Shop;
use App\Models\User;
use App\Services\Governance\GovernanceAuditService;
use Illuminate\Database\Eloquent\Model;

class AdminAuthAuditService
{
    public function __construct(
        private readonly GovernanceAuditService $auditService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $eventType,
        string $summary,
        ?User $actor = null,
        ?Shop $shop = null,
        ?Model $target = null,
        ?ApprovalRequest $approvalRequest = null,
        string $severity = 'info',
        array $context = [],
        ?string $targetLabel = null,
    ): GovernanceAudit {
        return $this->auditService->record(
            GovernanceAudit::CATEGORY_AUTH,
            $eventType,
            $summary,
            $actor,
            $shop,
            $target,
            $approvalRequest,
            $severity,
            $context,
            $targetLabel
        );
    }
}
