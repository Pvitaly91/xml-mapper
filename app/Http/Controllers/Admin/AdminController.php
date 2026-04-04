<?php

namespace App\Http\Controllers\Admin;

use App\Data\Governance\GovernedActionResult;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Shop;
use App\Services\Admin\CurrentAdminShopResolver;
use App\Services\Governance\GovernedActionService;
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
}
