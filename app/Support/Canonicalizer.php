<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Canonicalizer
{
    public static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeKey(string $value): string
    {
        $normalized = Str::of(self::normalizeText($value) ?? '')
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return $normalized === '' ? 'undefined' : $normalized;
    }

    public static function fingerprint(array|string|null $payload): string
    {
        $value = is_array($payload)
            ? json_encode(self::sortRecursive($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : (string) ($payload ?? '');

        return sha1($value);
    }

    public static function firstMatchingValue(array $attributes, array $candidateKeys): ?string
    {
        $normalizedCandidates = collect($candidateKeys)
            ->map(fn (string $key) => self::normalizeKey($key))
            ->all();

        $dictionary = collect($attributes)
            ->mapWithKeys(fn ($value, $key) => [self::normalizeKey((string) $key) => is_array($value) ? implode(', ', $value) : $value]);

        foreach ($normalizedCandidates as $candidate) {
            $value = $dictionary->get($candidate);

            if ($value !== null && self::normalizeText((string) $value) !== null) {
                return self::normalizeText((string) $value);
            }
        }

        return null;
    }

    public static function sortRecursive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::sortRecursive($value);
            }
        }

        ksort($payload);

        return $payload;
    }

    public static function uniqueNonEmpty(array $values): array
    {
        return Collection::make($values)
            ->map(fn ($value) => self::normalizeText(is_scalar($value) ? (string) $value : null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
