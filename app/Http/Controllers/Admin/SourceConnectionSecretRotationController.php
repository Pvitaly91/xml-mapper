<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\SourceConnections\SourceConnectionRotationRequest;
use App\Models\SourceConnection;
use App\Services\Ops\SecretsRotationService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class SourceConnectionSecretRotationController extends AdminController
{
    public function store(
        SourceConnectionRotationRequest $request,
        SourceConnection $sourceConnection,
        SecretsRotationService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $sourceConnection);

        try {
            $service->record(
                $request->validated('target'),
                $sourceConnection,
                null,
                $request->user(),
                $request->validated('note')
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Secret rotation metadata recorded.');
    }
}
