<?php

namespace App\Actions\Admin\SourceConnections;

use App\Contracts\Source\SourceConnectionTestServiceInterface;
use App\Data\Source\SourceConnectionCheckResult;
use App\Models\SourceConnection;

class RunSourceConnectionTestAction
{
    public function __construct(
        private readonly SourceConnectionTestServiceInterface $tester,
    ) {}

    public function handle(SourceConnection $connection): SourceConnectionCheckResult
    {
        return $this->tester->test($connection);
    }
}
