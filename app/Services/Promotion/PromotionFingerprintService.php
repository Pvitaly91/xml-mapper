<?php

namespace App\Services\Promotion;

class PromotionFingerprintService
{
    public function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode($this->normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        $normalized = [];
        ksort($value);

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalize($item);
        }

        return $normalized;
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
