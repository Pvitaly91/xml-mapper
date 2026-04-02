<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SourceConnections\RunSourceConnectionTestAction;
use App\Models\SourceConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SourceConnectionTestController extends AdminController
{
    public function store(Request $request, SourceConnection $sourceConnection, RunSourceConnectionTestAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $sourceConnection);

        $result = $action->handle($sourceConnection);

        return back()->with(
            $result->status === SourceConnection::CHECK_STATUS_OK ? 'status' : 'error',
            $result->message
        );
    }
}
