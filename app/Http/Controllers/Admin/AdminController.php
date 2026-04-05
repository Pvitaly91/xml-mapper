<?php

namespace App\Http\Controllers\Admin;

use App\Data\Auth\StepUpResult;
use App\Data\Governance\GovernedActionResult;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Shop;
use App\Services\Admin\CurrentAdminShopResolver;
use App\Services\Governance\GovernedActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class AdminController extends Controller
{
    protected function adminShop(Request $request): Shop
    {
        return app(CurrentAdminShopResolver::class)->require($request);
    }

    protected function ensureShopOwned(Request $request, Model $model): void
    {
        app(CurrentAdminShopResolver::class)->ensureOwns($request, $model);
    }

    protected function currentShop(Request $request): ?Shop
    {
        return app(CurrentAdminShopResolver::class)->resolve($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $payloadSummary
     */
    protected function dispatchGovernedAction(
        Request $request,
        GovernedActionService $service,
        string $action,
        ?Model $target,
        array $payload,
        array $payloadSummary = [],
        ?string $reason = null,
        ?string $note = null,
        ?string $targetLabel = null,
    ): GovernedActionResult {
        return $service->dispatch(
            $action,
            $request->user(),
            $this->currentShop($request),
            $target,
            $payload,
            $payloadSummary,
            $reason,
            $note,
            $targetLabel
        );
    }

    protected function governedFlashKey(GovernedActionResult $result): string
    {
        return in_array($result->status, [
            ApprovalRequest::STATUS_REJECTED,
            'blocked',
            'blocked_by_policy',
            'password_reauth_required',
            'mfa_reauth_required',
        ], true)
            ? 'error'
            : 'status';
    }

    protected function redirectWithGovernedResult(
        Request $request,
        GovernedActionResult $result,
        ?string $executedMessage = null,
        ?string $pendingMessage = null,
        ?string $returnTo = null,
    ): RedirectResponse {
        $returnTo ??= url()->previous();
        $message = $result->status === 'executed'
            ? ($executedMessage ?: ($result->message ?: 'Action executed.'))
            : ($result->message ?: $pendingMessage ?: 'Approval workflow started.');

        if (in_array($result->status, ['password_reauth_required', 'mfa_reauth_required', 'blocked_by_policy'], true)) {
            return $this->redirectWithStepUpResult(
                $request,
                new StepUpResult($result->status, $message),
                $pendingMessage ?: 'Sensitive action was blocked pending additional verification.',
                $returnTo
            );
        }

        $response = redirect()->to($returnTo)->with($this->governedFlashKey($result), $message);

        if ($result->status === 'approval_required' && $result->approvalRequest instanceof ApprovalRequest) {
            $response->with('admin_governance_feedback', [
                'status' => $result->status,
                'title' => 'Approval queued',
                'message' => $message,
                'action_label' => 'Open approval request',
                'action_url' => route('admin.access.approvals.show', $result->approvalRequest),
                'approval_id' => $result->approvalRequest->id,
            ]);
        }

        return $response;
    }

    protected function redirectWithStepUpResult(
        Request $request,
        StepUpResult $result,
        string $actionSummary,
        ?string $returnTo = null,
    ): RedirectResponse {
        $returnTo ??= url()->previous();
        $feedback = [
            'status' => $result->status,
            'title' => 'Additional verification required',
            'message' => $result->message ?: $actionSummary,
        ];

        if ($result->status === 'password_reauth_required') {
            $request->session()->put('admin_step_up', [
                'required' => 'password',
                'intended_url' => $returnTo,
                'action_summary' => $actionSummary,
            ]);
            $feedback['action_label'] = 'Confirm password';
            $feedback['action_url'] = route('admin.auth.reauth.password.create');
        } elseif ($result->status === 'mfa_reauth_required') {
            $request->session()->put('admin_step_up', [
                'required' => 'mfa',
                'intended_url' => $returnTo,
                'action_summary' => $actionSummary,
            ]);
            $feedback['action_label'] = 'Confirm MFA';
            $feedback['action_url'] = route('admin.auth.reauth.mfa.create');
        } elseif ($result->status === 'blocked_by_policy' && ! $request->user()?->hasMfaEnabled()) {
            $feedback['title'] = 'Policy blocked the action';
            $feedback['action_label'] = 'Open MFA setup';
            $feedback['action_url'] = route('admin.auth.mfa.setup');
        }

        return redirect()
            ->to($returnTo)
            ->with('error', $result->message ?: $actionSummary)
            ->with('admin_governance_feedback', $feedback);
    }
}
