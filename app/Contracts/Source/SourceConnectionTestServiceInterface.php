<?php

namespace App\Contracts\Source;

use App\Data\Source\SourceConnectionCheckResult;
use App\Models\SourceConnection;

interface SourceConnectionTestServiceInterface
{
    public function test(SourceConnection $connection): SourceConnectionCheckResult;
}
