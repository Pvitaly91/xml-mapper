<?php

namespace App\Services\Feeds;

use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Support\Canonicalizer;

class FeedItemEnrichmentService
{
    public function __construct(
        private readonly KastaExportFieldNormalizer $fieldNormalizer,
        private readonly VariantFamilyService $variantFamilyService,
    ) {}

    /**
     * @param  array<string, string>  $mappedAttributes
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $contentOverrides
     * @return array<string, mixed>
     */
    public function preview(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        array $mappedAttributes,
        array $contract,
        array $contentOverrides = []
    ): array {
        $rules = [];
        $warnings = [];
        $rawVendor = $this->fieldNormalizer->normalizeVendor($product->vendor, $product->brand)['value'];
        $rawArticle = $this->fieldNormalizer->normalizeArticle($product->article)['value'];
        $rawColor = $this->fieldNormalizer->normalizeColor(
            Canonicalizer::firstMatchingValue($mappedAttributes, ['color', 'colour']) ?: $variant->color
        )['value'];
        $rawSize = $this->fieldNormalizer->normalizeSize(
            Canonicalizer::firstMatchingValue($mappedAttributes, ['size']) ?: $variant->size
        )['value'];
        $rawTitle = Canonicalizer::normalizeText($variant->title ?: $product->name);
        $rawDescription = $this->normalizeDescription($product->description);
        $rawImages = $this->selectImages($product, $variant, $rules, $warnings);

        $content = [
            'title' => $this->buildTitle($rawTitle, $rawVendor, $rawColor, $rawSize, $contract, $rules),
            'description' => $this->buildDescription(
                $rawDescription,
                $product,
                $rawVendor,
                $rawArticle,
                $rawColor,
                $rawSize,
                $contract,
                $rules,
                $warnings
            ),
            'images' => $rawImages,
            'vendor' => $rawVendor,
            'article' => $rawArticle,
            'color' => $rawColor,
            'size' => $rawSize,
            'size_grid_code' => null,
        ];

        $manualOverrideActive = (bool) ($contentOverrides['has_manual_override'] ?? false);

        foreach (['title', 'description', 'vendor', 'article', 'color', 'size', 'size_grid_code'] as $field) {
            $overrideValue = $contentOverrides['fields'][$field] ?? null;

            if ($overrideValue === null) {
                continue;
            }

            $content[$field] = $this->normalizeFieldValue($field, $overrideValue);
            $rules[] = 'override.'.$field;
        }

        if (($contentOverrides['images'] ?? []) !== []) {
            $content['images'] = $this->fieldNormalizer->normalizePictures((array) $contentOverrides['images']);
            $rules[] = 'override.images';
        }

        $family = $this->variantFamilyService->buildContext($feedProfile, $product, $variant, $content, $contract);

        if (($contentOverrides['fields']['size_grid_code'] ?? null) === null && ($family['size_grid_code'] ?? null) !== null) {
            $content['size_grid_code'] = $family['size_grid_code'];
            $rules[] = 'size_grid.auto_resolved';
        }

        if ($manualOverrideActive) {
            $warnings[] = [
                'code' => 'manual_content_override_active',
                'message' => 'Manual content override is active and remains authoritative for this item.',
            ];
        }

        $current = [
            'title' => $rawTitle,
            'description' => $rawDescription,
            'images' => $rawImages,
            'vendor' => $rawVendor,
            'article' => $rawArticle,
            'color' => $rawColor,
            'size' => $rawSize,
            'size_grid_code' => null,
        ];
        $diff = $this->diff($current, $content);

        return [
            'content' => $content,
            'current' => $current,
            'contract' => $contract,
            'family' => $family,
            'rules' => array_values(array_unique($rules)),
            'warnings' => $warnings,
            'manual_override_active' => $manualOverrideActive,
            'diff' => $diff,
            'apply_payload' => $this->applyPayload($diff, $content),
            'has_suggested_changes' => collect($diff)->contains(fn (array $row) => $row['changed']),
        ];
    }

    private function buildTitle(?string $title, ?string $vendor, ?string $color, ?string $size, array $contract, array &$rules): ?string
    {
        $base = $title;

        if ($base === null) {
            $base = Canonicalizer::normalizeText(implode(' ', array_filter([$vendor, $color, $size])));
            $rules[] = 'title.fallback_from_core_fields';
        }

        if ($base === null) {
            return null;
        }

        $segments = [$base];
        $baseKey = Canonicalizer::normalizeKey($base);

        if (($contract['title_rules']['prepend_vendor'] ?? false) && $vendor !== null) {
            $vendorKey = Canonicalizer::normalizeKey($vendor);

            if ($vendorKey !== 'undefined' && ! $this->containsSemanticToken($baseKey, $vendorKey)) {
                array_unshift($segments, $vendor);
                $rules[] = 'title.prepend_vendor';
            }
        }

        if (($contract['title_rules']['append_color'] ?? false) && $color !== null) {
            $colorKey = Canonicalizer::normalizeKey($color);

            if ($colorKey !== 'undefined' && ! $this->containsSemanticToken($baseKey, $colorKey)) {
                $segments[] = $color;
                $rules[] = 'title.append_color';
            }
        }

        if (($contract['title_rules']['append_size'] ?? false) && $size !== null) {
            $sizeKey = Canonicalizer::normalizeKey($size);

            if ($sizeKey !== 'undefined' && ! $this->containsSemanticToken($baseKey, $sizeKey)) {
                $segments[] = $size;
                $rules[] = 'title.append_size';
            }
        }

        $normalized = Canonicalizer::normalizeText(implode(' ', array_filter($segments)));
        $maxLength = max(40, (int) ($contract['title_rules']['max_length'] ?? 160));

        if ($normalized !== null && mb_strlen($normalized) > $maxLength) {
            $normalized = Canonicalizer::normalizeText(mb_substr($normalized, 0, $maxLength));
            $rules[] = 'title.truncated';
        }

        return $normalized;
    }

    private function buildDescription(
        ?string $description,
        SourceProduct $product,
        ?string $vendor,
        ?string $article,
        ?string $color,
        ?string $size,
        array $contract,
        array &$rules,
        array &$warnings
    ): ?string {
        if ($description !== null) {
            $maxLength = max(200, (int) ($contract['description_rules']['max_length'] ?? 2000));

            if (mb_strlen($description) > $maxLength) {
                $description = Canonicalizer::normalizeText(mb_substr($description, 0, $maxLength));
                $rules[] = 'description.truncated';
            }

            return $description;
        }

        if (! ($contract['description_rules']['allow_fallback'] ?? true)) {
            return null;
        }

        $segments = array_filter([
            Canonicalizer::normalizeText($product->name),
            $vendor !== null ? 'Бренд: '.$vendor : null,
            $article !== null ? 'Артикул: '.$article : null,
            $color !== null ? 'Колір: '.$color : null,
            $size !== null ? 'Розмір: '.$size : null,
        ]);

        if ($segments === []) {
            return null;
        }

        $warnings[] = [
            'code' => 'description_fallback',
            'message' => 'Description was reconstructed from deterministic catalog fields.',
        ];
        $rules[] = 'description.fallback_from_core_fields';

        return Canonicalizer::normalizeText(implode('. ', $segments).'.');
    }

    /**
     * @return list<string>
     */
    private function selectImages(SourceProduct $product, SourceVariant $variant, array &$rules, array &$warnings): array
    {
        $candidates = [
            ...($variant->images_json ?? []),
            ...($product->images_json ?? []),
            $product->primary_image_url,
        ];
        $normalized = $this->fieldNormalizer->normalizePictures($candidates);
        $valid = [];
        $invalid = [];

        foreach ($normalized as $picture) {
            if (filter_var($picture, FILTER_VALIDATE_URL) === false) {
                $invalid[] = $picture;

                continue;
            }

            $valid[] = $picture;
        }

        if ($invalid !== []) {
            $warnings[] = [
                'code' => 'images_invalid_removed',
                'message' => sprintf('%d invalid image URL(s) were removed from export selection.', count($invalid)),
            ];
            $rules[] = 'images.remove_invalid';
        }

        if (($variant->images_json ?? []) !== []) {
            $rules[] = 'images.variant_first';
        }

        return $valid;
    }

    private function normalizeDescription(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Canonicalizer::normalizeText(strip_tags($value));
    }

    /**
     * @return array<string, array{current:mixed,final:mixed,changed:bool}>
     */
    private function diff(array $current, array $final): array
    {
        $diff = [];

        foreach ($final as $field => $value) {
            $currentValue = $current[$field] ?? null;
            $changed = is_array($value) || is_array($currentValue)
                ? json_encode($currentValue, JSON_THROW_ON_ERROR) !== json_encode($value, JSON_THROW_ON_ERROR)
                : $currentValue !== $value;

            $diff[$field] = [
                'current' => $currentValue,
                'final' => $value,
                'changed' => $changed,
            ];
        }

        return $diff;
    }

    /**
     * @param  array<string, array{current:mixed,final:mixed,changed:bool}>  $diff
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function applyPayload(array $diff, array $content): array
    {
        $payload = [];

        foreach ($diff as $field => $row) {
            if (! $row['changed']) {
                continue;
            }

            $payload[$field] = $content[$field] ?? null;
        }

        return $payload;
    }

    private function normalizeFieldValue(string $field, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return match ($field) {
            'article' => $this->fieldNormalizer->normalizeArticle((string) $value)['value'],
            'size' => $this->fieldNormalizer->normalizeSize((string) $value)['value'],
            default => Canonicalizer::normalizeText((string) $value),
        };
    }

    private function containsSemanticToken(string $haystackKey, string $needleKey): bool
    {
        $haystackTokens = array_values(array_filter(explode('_', $haystackKey)));
        $needleTokens = array_values(array_filter(explode('_', $needleKey)));

        if ($haystackTokens === [] || $needleTokens === []) {
            return false;
        }

        $window = count($needleTokens);

        for ($index = 0; $index <= count($haystackTokens) - $window; $index++) {
            if (array_slice($haystackTokens, $index, $window) === $needleTokens) {
                return true;
            }
        }

        return false;
    }
}
