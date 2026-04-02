<?php

namespace App\Contracts\Dictionaries;

interface DictionaryReaderInterface
{
    public function format(): string;

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function read(string $path): iterable;
}
