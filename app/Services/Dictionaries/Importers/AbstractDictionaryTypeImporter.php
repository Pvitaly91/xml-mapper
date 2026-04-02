<?php

namespace App\Services\Dictionaries\Importers;

use App\Support\Canonicalizer;
use RuntimeException;

abstract class AbstractDictionaryTypeImporter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(iterable $rows): array
    {
        $materialized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Dictionary row must be an associative array.');
            }

            $materialized[] = $row;
        }

        return $materialized;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function required(array $row, string $key): mixed
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Dictionary row is missing required key [%s].', $key));
        }

        return $value;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        return Canonicalizer::normalizeText((string) $value);
    }

    protected function boolean(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected function integer(mixed $value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function jsonField(array $row, string $arrayKey, string $jsonKey): ?array
    {
        $value = $row[$arrayKey] ?? $row[$jsonKey] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function valuesEqual(mixed $left, mixed $right): bool
    {
        return Canonicalizer::fingerprint($left) === Canonicalizer::fingerprint($right);
    }

    protected function compositeKey(string ...$segments): string
    {
        return implode('|', $segments);
    }
}
