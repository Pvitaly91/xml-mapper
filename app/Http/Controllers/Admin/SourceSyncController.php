<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSourceJob;
use App\Models\SourceConnection;
use Illuminate\Http\JsonResponse;

class SourceSyncController extends Controller
{
    public function store(int $id): JsonResponse
    {
        $connection = SourceConnection::findOrFail($id);

        SyncSourceJob::dispatch($connection->id);

        return response()->json([
            'status' => 'queued',
            'source_connection_id' => $connection->id,
        ], 202);
    }
}
