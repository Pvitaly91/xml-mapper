<?php

namespace App\Actions\Admin\Dictionaries;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Models\DictionaryImport;

class RunDictionaryImportAction
{
    public function __construct(
        private readonly KastaDictionaryImportServiceInterface $service,
    ) {
    }

    public function handle(DictionaryImportOptions $options): DictionaryImport
    {
        return $this->service->import($options);
    }
}
