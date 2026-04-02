<?php

namespace App\Contracts\Source;

use App\Models\SourceConnection;
use App\Models\SourceImport;

interface SourceSyncWorkflowServiceInterface
{
    public function prepare(SourceConnection $connection): SourceImport;

    public function normalize(SourceImport $import, bool $dispatchBuilds = true): SourceImport;

    public function run(SourceConnection $connection, bool $dispatchBuilds = true): SourceImport;
}
