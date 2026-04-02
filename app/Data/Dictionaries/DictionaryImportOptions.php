<?php

namespace App\Data\Dictionaries;

readonly class DictionaryImportOptions
{
    public function __construct(
        public string $type,
        public ?string $filePath = null,
        public ?string $format = null,
        public bool $dryRun = false,
        public bool $deactivateMissing = false,
        public bool $allowDuplicateChecksum = false,
        public ?int $initiatedByUserId = null,
        public ?string $originalFilename = null,
    ) {
    }
}
