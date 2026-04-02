<?php

namespace App\Contracts\Dictionaries;

use App\Data\Dictionaries\DictionaryImportOptions;
use App\Models\DictionaryImport;

interface KastaDictionaryImportServiceInterface
{
    public function import(DictionaryImportOptions $options): DictionaryImport;

    public function reimportLatest(string $type, bool $dryRun = false, bool $deactivateMissing = false, ?int $initiatedByUserId = null): DictionaryImport;

    /**
     * @return array{categories:int,attributes:int,attribute_values:int,size_grids:int}
     */
    public function importBundle(?string $directory = null, ?int $initiatedByUserId = null): array;

    /**
     * @return list<string>
     */
    public function supportedTypes(): array;

    /**
     * @return list<string>
     */
    public function supportedFormats(): array;

    public function sampleFileFor(string $type, string $format = 'json'): string;
}
