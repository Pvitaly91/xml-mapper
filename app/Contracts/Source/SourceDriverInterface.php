<?php

namespace App\Contracts\Source;

use App\Data\Source\ParsedSourceFeedData;
use App\Data\Source\SourceConnectionCheckResult;
use App\Models\SourceConnection;
use App\Models\SourceImport;

interface SourceDriverInterface
{
    public function driver(): string;

    public function testConnection(SourceConnection $connection): SourceConnectionCheckResult;

    public function sync(SourceConnection $connection): SourceImport;

    public function loadFeedData(SourceConnection $connection, SourceImport $import): ParsedSourceFeedData;
}
