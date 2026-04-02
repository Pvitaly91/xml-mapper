<?php

namespace App\Contracts\Dictionaries;

use App\Data\Dictionaries\DictionaryImportOptions;
use App\Data\Dictionaries\DictionaryImportResult;

interface DictionaryTypeImporterInterface
{
    public function type(): string;

    public function import(iterable $rows, DictionaryImportOptions $options): DictionaryImportResult;
}
