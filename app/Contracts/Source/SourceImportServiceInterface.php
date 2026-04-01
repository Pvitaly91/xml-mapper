<?php

namespace App\Contracts\Source;

use App\Models\SourceConnection;
use App\Models\SourceImport;

interface SourceImportServiceInterface
{
    public function sync(SourceConnection $connection): SourceImport;
}
