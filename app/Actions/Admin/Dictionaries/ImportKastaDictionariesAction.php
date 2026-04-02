<?php

namespace App\Actions\Admin\Dictionaries;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;

class ImportKastaDictionariesAction
{
    public function __construct(
        private readonly KastaDictionaryImportServiceInterface $service,
    ) {
    }

    /**
     * @return array{categories:int,attributes:int,attribute_values:int,size_grids:int}
     */
    public function handle(?string $directory = null, ?int $initiatedByUserId = null): array
    {
        return $this->service->importBundle($directory, $initiatedByUserId);
    }
}
