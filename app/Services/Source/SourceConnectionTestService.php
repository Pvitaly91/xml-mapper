<?php

namespace App\Services\Source;

use App\Contracts\Source\SourceConnectionTestServiceInterface;
use App\Data\Source\SourceConnectionCheckResult;
use App\Exceptions\Source\SourceDriverException;
use App\Models\SourceConnection;

class SourceConnectionTestService implements SourceConnectionTestServiceInterface
{
    public function __construct(
        private readonly SourceDriverRegistry $drivers,
        private readonly SourceConnectionStateService $stateService,
    ) {}

    public function test(SourceConnection $connection): SourceConnectionCheckResult
    {
        try {
            $result = $this->drivers->forConnection($connection)->testConnection($connection);
        } catch (SourceDriverException $exception) {
            $result = new SourceConnectionCheckResult(
                status: $exception->status,
                message: $exception->getMessage(),
            );
        }

        $this->stateService->recordConnectionCheck($connection, $result);

        return $result;
    }
}
