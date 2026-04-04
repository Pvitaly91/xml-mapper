<?php

namespace App\Services\Governance;

use App\Data\Governance\GovernedActionResult;
use App\Models\ApprovalRequest;
use App\Models\Shop;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use App\Services\Auth\AdminStepUpAuthService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class GovernedActionService
{
    public function __construct(
        private readonly AdminAccessService $accessService,
        private readonly ApprovalPolicyService $policyService,
        private readonly GovernedActionRegistry $registry,
        private readonly GovernanceAuditService $auditService,
        private readonly AdminStepUpAuthService $stepUpAuthService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $payloadSummary
     */
    public function dispatch(
        string $action,
        User $actor,
        ?Shop $shop,
        ?Model $target,
        array $payload,
        array $payloadSummary = [],
        ?string $reason = null,
        ?string $note = null,
        ?string $targetLabel = null,
    ): GovernedActionResult {
        $rule = $this->policyService->rule($action, $shop);
        $category = $this->auditCategory($action);

        if (! $this->accessService->can($actor, (string) $rule['permission'], $shop ?? $target)) {
            $this->auditService->record(
                $category,
                'dangerous_action_blocked',
                'Sensitive action was blocked by permission policy.',
                $actor,
                $shop,
                $target,
                severity: 'warning',
                context: [
                    'action' => $action,
                    'reason' => $reason,
                    'policy' => $rule,
                ],
                targetLabel: $targetLabel
            );

            return new GovernedActionResult('blocked', message: 'You do not have permission to execute this action.');
        }

        if (($rule['platform_admin_only'] ?? false) === true && ! $this->accessService->isPlatformAdmin($actor)) {
            return new GovernedActionResult('blocked', message: 'This action is restricted to platform administrators.');
        }

        $stepUp = $this->stepUpAuthService->authorizeAction($action, $actor, $shop);

        if (! $stepUp->allowed()) {
            return new GovernedActionResult($stepUp->status, message: $stepUp->message);
        }

        if (($rule['approval_required'] ?? false) === true) {
            $approval = ApprovalRequest::create([
                'shop_id' => $shop?->id ?: $target?->getAttribute('shop_id'),
                'requested_by_user_id' => $actor->id,
                'action' => $action,
                'classification' => $rule['classification'],
                'environment_class' => $rule['environment_class'],
                'environment_label' => $rule['environment_label'],
                'status' => ApprovalRequest::STATUS_PENDING,
                'requires_four_eyes' => (bool) ($rule['four_eyes'] ?? false),
                'platform_admin_only' => (bool) ($rule['platform_admin_only'] ?? false),
                'target_type' => $target?->getMorphClass(),
                'target_id' => $target?->getKey(),
                'target_label' => $targetLabel,
                'correlation_id' => app(\App\Services\Ops\CorrelationContext::class)->id(),
                'reason' => $reason,
                'note' => $note,
                'payload_summary' => $payloadSummary,
                'payload' => $payload,
                'requested_at' => now(),
                'expires_at' => now()->addMinutes((int) ($rule['ttl_minutes'] ?? 120)),
            ]);

            $this->auditService->record(
                'approval',
                'approval_requested',
                'Approval request created for dangerous action.',
                $actor,
                $shop,
                $target,
                $approval,
                severity: 'warning',
                context: [
                    'action' => $action,
                    'classification' => $rule['classification'],
                    'payload_summary' => $payloadSummary,
                    'expires_at' => $approval->expires_at?->toIso8601String(),
                ],
                targetLabel: $targetLabel
            );

            return new GovernedActionResult(
                'approval_required',
                approvalRequest: $approval,
                message: 'Approval request #'.$approval->id.' created.'
            );
        }

        $execution = $this->registry->handler($action)->execute($payload, $actor);

        $this->auditService->record(
            $category,
            'dangerous_action_executed',
            'Sensitive action executed directly.',
            $actor,
            $shop,
            $target,
            severity: 'warning',
            context: [
                'action' => $action,
                'payload_summary' => $payloadSummary,
                'execution' => $execution,
            ],
            targetLabel: $targetLabel
        );

        return new GovernedActionResult(
            'executed',
            execution: $execution,
            message: 'Action executed.'
        );
    }

    public function approve(ApprovalRequest $approvalRequest, User $actor, ?string $note = null): ApprovalRequest
    {
        $shop = $approvalRequest->shop;

        if (! $this->accessService->canReviewApprovals($actor, $shop)) {
            throw new RuntimeException('You are not allowed to review approvals for this shop.');
        }

        $stepUp = $this->stepUpAuthService->authorizeAction($approvalRequest->action, $actor, $shop);

        if (! $stepUp->allowed()) {
            throw new RuntimeException($stepUp->message ?: 'Additional verification is required.');
        }

        if ($approvalRequest->isExpired()) {
            $approvalRequest->forceFill([
                'status' => ApprovalRequest::STATUS_EXPIRED,
            ])->save();

            throw new RuntimeException('Approval request has expired.');
        }

        if ($approvalRequest->status === ApprovalRequest::STATUS_REJECTED) {
            throw new RuntimeException('Approval request was already rejected.');
        }

        if ($approvalRequest->status === ApprovalRequest::STATUS_EXECUTED) {
            return $approvalRequest;
        }

        if ($approvalRequest->requires_four_eyes && $approvalRequest->requested_by_user_id === $actor->id) {
            throw new RuntimeException('Requester cannot approve this high-risk action.');
        }

        $approvalRequest->forceFill([
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'note' => $note ?: $approvalRequest->note,
        ])->save();

        $this->auditService->record(
            'approval',
            'approval_approved',
            'Approval request approved.',
            $actor,
            $shop,
            $approvalRequest->target,
            $approvalRequest,
            severity: 'warning',
            context: [
                'action' => $approvalRequest->action,
                'payload_summary' => $approvalRequest->payload_summary,
            ],
            targetLabel: $approvalRequest->target_label
        );

        $execution = $this->registry->handler($approvalRequest->action)
            ->execute((array) ($approvalRequest->payload ?? []), $actor, $approvalRequest);

        $approvalRequest->forceFill([
            'status' => ApprovalRequest::STATUS_EXECUTED,
            'executed_at' => now(),
            'result_summary' => $execution,
        ])->save();

        $this->auditService->record(
            $this->auditCategory($approvalRequest->action),
            'approval_executed',
            'Approved action executed.',
            $actor,
            $shop,
            $approvalRequest->target,
            $approvalRequest,
            severity: 'warning',
            context: [
                'action' => $approvalRequest->action,
                'execution' => $execution,
            ],
            targetLabel: $approvalRequest->target_label
        );

        return $approvalRequest->fresh(['requestedBy', 'approvedBy', 'target']);
    }

    public function reject(ApprovalRequest $approvalRequest, User $actor, ?string $note = null): ApprovalRequest
    {
        if (! $this->accessService->canReviewApprovals($actor, $approvalRequest->shop)) {
            throw new RuntimeException('You are not allowed to review approvals for this shop.');
        }

        if ($approvalRequest->status === ApprovalRequest::STATUS_EXECUTED) {
            throw new RuntimeException('Executed approvals cannot be rejected.');
        }

        $approvalRequest->forceFill([
            'status' => ApprovalRequest::STATUS_REJECTED,
            'approved_by_user_id' => $actor->id,
            'rejected_at' => now(),
            'note' => $note ?: $approvalRequest->note,
        ])->save();

        $this->auditService->record(
            'approval',
            'approval_rejected',
            'Approval request rejected.',
            $actor,
            $approvalRequest->shop,
            $approvalRequest->target,
            $approvalRequest,
            severity: 'warning',
            context: [
                'action' => $approvalRequest->action,
                'payload_summary' => $approvalRequest->payload_summary,
            ],
            targetLabel: $approvalRequest->target_label
        );

        return $approvalRequest->fresh(['requestedBy', 'approvedBy', 'target']);
    }

    private function auditCategory(string $action): string
    {
        return str_starts_with($action, 'source.secret_') ? 'secret' : 'dangerous_action';
    }
}
