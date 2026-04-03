<?php

namespace App\Actions\Admin\SourceConnections;

use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Models\SourceConnection;
use App\Models\SourceImport;

class RunSourceConnectionSyncAction
{
    public function __construct(
        private readonly SourceSyncWorkflowServiceInterface $workflow,
    ) {}

    public function handle(SourceConnection $connection): SourceImport
    {
        return $this->workflow->run($connection);
    }
}
