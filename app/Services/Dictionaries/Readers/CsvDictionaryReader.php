<?php

namespace App\Services\Dictionaries\Readers;

use App\Contracts\Dictionaries\DictionaryReaderInterface;
use RuntimeException;
use SplFileObject;

class CsvDictionaryReader implements DictionaryReaderInterface
{
    public function format(): string
    {
        return 'csv';
    }

    public function read(string $path): iterable
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $headers = null;

        foreach ($file as $row) {
            if (! is_array($row)) {
                continue;
            }

            $row = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $row);

            if ($headers === null) {
                $headers = $row;

                continue;
            }

            if (count(array_filter($row, static fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            if ($headers === [null] || $headers === []) {
                throw new RuntimeException('CSV dictionary file must contain a header row.');
            }

            $payload = [];

            foreach ($headers as $index => $header) {
                if (! is_string($header) || trim($header) === '') {
                    continue;
                }

                $payload[$header] = $row[$index] ?? null;
            }

            yield $payload;
        }
    }
}
