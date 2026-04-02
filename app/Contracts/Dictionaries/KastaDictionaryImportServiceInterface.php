<?php

namespace App\Contracts\Dictionaries;

interface KastaDictionaryImportServiceInterface
{
    /**
     * @return array{categories:int,attributes:int,attribute_values:int,size_grids:int}
     */
    public function import(?string $directory = null): array;
}
