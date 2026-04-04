<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\SourceConnections\SourceConnectionRotationRequest;
use App\Models\SourceConnection;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class SourceConnectionSecretRotationController extends AdminController
{
    public function store(
        SourceConnectionRotationRequest $request,
        SourceConnection $sourceConnection,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $sourceConnection);

        try {
            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_SECRET_ROTATION,
                $sourceConnection,
                [
                    'source_connection_id' => $sourceConnection->id,
                    'target' => (string) $request->validated('target'),
                    'note' => $request->validated('note'),
                ],
                [
                    'source_connection_id' => $sourceConnection->id,
                    'target' => (string) $request->validated('target'),
                ],
                (string) ($request->validated('note') ?: 'Secret rotation confirmation requested.'),
                targetLabel: $sourceConnection->name
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            $this->governedFlashKey($result),
            $result->status === 'executed'
                ? 'Secret rotation metadata recorded.'
                : ($result->message ?: 'Approval workflow started for secret rotation.')
        );
    }
}
