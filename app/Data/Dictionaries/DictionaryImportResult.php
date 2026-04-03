<?php

namespace App\Data\Dictionaries;

readonly class DictionaryImportResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $rowsTotal,
        public int $createdCount,
        public int $updatedCount,
        public int $skippedCount,
        public int $deactivatedCount,
        public array $metadata = [],
    ) {}
}
