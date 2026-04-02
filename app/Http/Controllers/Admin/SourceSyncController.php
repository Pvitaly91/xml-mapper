<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SourceConnections\RunSourceConnectionSyncAction;
use App\Models\SourceConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class SourceSyncController extends AdminController
{
    public function store(Request $request, SourceConnection $sourceConnection, RunSourceConnectionSyncAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $sourceConnection);

        try {
            $import = $action->handle($sourceConnection);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Source synced. Latest import status: '.$import->status.'.');
    }
}
