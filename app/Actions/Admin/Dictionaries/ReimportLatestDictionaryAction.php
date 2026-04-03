<?php

namespace App\Actions\Admin\Dictionaries;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Models\DictionaryImport;

class ReimportLatestDictionaryAction
{
    public function __construct(
        private readonly KastaDictionaryImportServiceInterface $service,
    ) {}

    public function handle(string $type, bool $dryRun = false, bool $deactivateMissing = false, ?int $initiatedByUserId = null): DictionaryImport
    {
        return $this->service->reimportLatest($type, $dryRun, $deactivateMissing, $initiatedByUserId);
    }
}
