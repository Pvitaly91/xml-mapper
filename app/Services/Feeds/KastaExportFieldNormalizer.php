<?php

namespace App\Services\Feeds;

use App\Support\Canonicalizer;

class KastaExportFieldNormalizer
{
    /**
     * @return array{raw:?string,value:?string,key:?string}
     */
    public function normalizeVendor(?string $vendor, ?string $brand = null): array
    {
        return $this->normalizeDisplayValue($vendor ?: $brand);
    }

    /**
     * @return array{raw:?string,value:?string,key:?string}
     */
    public function normalizeArticle(?string $article): array
    {
        $raw = Canonicalizer::normalizeText($article);

        if ($raw === null) {
            return $this->emptyValue();
        }

        $value = Canonicalizer::normalizeText(mb_strtoupper((string) preg_replace('/\s+/u', '', $raw)));

        return [
            'raw' => $raw,
            'value' => $value,
            'key' => $value !== null ? Canonicalizer::normalizeKey($value) : null,
        ];
    }

    /**
     * @return array{raw:?string,value:?string,key:?string}
     */
    public function normalizeColor(?string $value): array
    {
        return $this->normalizeDisplayValue($value);
    }

    /**
     * @return array{raw:?string,value:?string,key:?string}
     */
    public function normalizeSize(?string $value): array
    {
        $raw = Canonicalizer::normalizeText($value);

        if ($raw === null) {
            return $this->emptyValue();
        }

        $normalized = Canonicalizer::normalizeText(mb_strtoupper($raw));

        return [
            'raw' => $raw,
            'value' => $normalized,
            'key' => $normalized !== null ? Canonicalizer::normalizeKey($normalized) : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function normalizePictures(array $pictures): array
    {
        return Canonicalizer::uniqueNonEmpty($pictures);
    }

    /**
     * @return array{raw:?string,value:?string,key:?string}
     */
    public function normalizeDisplayValue(?string $value): array
    {
        $raw = Canonicalizer::normalizeText($value);

        if ($raw === null) {
            return $this->emptyValue();
        }

        return [
            'raw' => $raw,
            'value' => $raw,
            'key' => Canonicalizer::normalizeKey($raw),
        ];
    }

    /**
     * @return array{raw:null,value:null,key:null}
     */
    private function emptyValue(): array
    {
        return [
            'raw' => null,
            'value' => null,
            'key' => null,
        ];
    }
}
