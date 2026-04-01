<?php

namespace App\Contracts\Source;

use App\Data\Source\ParsedSourceFeedData;
use App\Models\SourceConnection;
use App\Models\SourceImport;

interface ProductNormalizerInterface
{
    /**
     * @return array{categories:int,products:int,variants:int}
     */
    public function normalize(SourceConnection $connection, SourceImport $import, ParsedSourceFeedData $feedData): array;
}
