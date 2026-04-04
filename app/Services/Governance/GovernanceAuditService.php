<?php

namespace App\Services\Governance;

use App\Models\ApprovalRequest;
use App\Models\GovernanceAudit;
use App\Models\Shop;
use App\Models\User;
use App\Services\Ops\CorrelationContext;
use App\Support\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Model;

class GovernanceAuditService
{
    public function __construct(
        private readonly CorrelationContext $correlationContext,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $category,
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
        return GovernanceAudit::create([
            'shop_id' => $shop?->id ?: $target?->getAttribute('shop_id'),
            'user_id' => $actor?->id,
            'approval_request_id' => $approvalRequest?->id,
            'category' => $category,
            'event_type' => $eventType,
            'severity' => $severity,
            'summary' => $summary,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'target_label' => $targetLabel,
            'correlation_id' => $this->correlationContext->id(),
            'context' => $this->redactor->redactArray($context),
            'occurred_at' => now(),
        ]);
    }
}
