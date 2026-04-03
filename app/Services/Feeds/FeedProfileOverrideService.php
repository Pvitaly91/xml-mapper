<?php

namespace App\Services\Feeds;

use App\Models\FeedProfile;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Support\Canonicalizer;

class FeedProfileOverrideService
{
    /**
     * @return array<string, mixed>
     */
    public function settings(FeedProfile $feedProfile): array
    {
        return array_merge([
            'excluded_source_category_ids' => [],
            'excluded_vendors' => [],
            'minimum_price_threshold' => null,
            'override_minimum_pictures' => null,
            'forced_attribute_overrides' => [],
            'forced_value_overrides' => [],
            'disabled_export_category_ids' => [],
        ], $feedProfile->exportSettings());
    }

    public function effectiveMinimumPictures(FeedProfile $feedProfile): int
    {
        $override = $feedProfile->overrideMinimumPictures();

        return $override ?? $feedProfile->minimumPictures();
    }

    public function minimumPriceThreshold(FeedProfile $feedProfile): ?float
    {
        return $feedProfile->minimumPriceThreshold();
    }

    public function exclusionReason(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?array $mappedCategory = null
    ): ?string {
        $settings = $this->settings($feedProfile);
        $excludedSourceCategoryIds = array_map('intval', (array) ($settings['excluded_source_category_ids'] ?? []));
        $disabledCategoryIds = array_map('strval', (array) ($settings['disabled_export_category_ids'] ?? []));
        $excludedVendors = collect((array) ($settings['excluded_vendors'] ?? []))
            ->map(static fn ($value) => Canonicalizer::normalizeKey((string) $value))
            ->filter()
            ->all();
        $vendorKey = Canonicalizer::normalizeKey((string) ($product->vendor ?: $product->brand ?: ''));

        if (in_array((int) $product->source_category_id, $excludedSourceCategoryIds, true)) {
            return 'Excluded by merchant rule: source category is disabled for export.';
        }

        if ($vendorKey !== 'undefined' && in_array($vendorKey, $excludedVendors, true)) {
            return 'Excluded by merchant rule: vendor/brand is disabled for export.';
        }

        $mappedExternalId = (string) ($mappedCategory['external_id'] ?? '');

        if ($mappedExternalId !== '' && in_array($mappedExternalId, $disabledCategoryIds, true)) {
            return 'Excluded by merchant rule: mapped Kasta category is disabled for export.';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function forcedAttributeOverrides(FeedProfile $feedProfile): array
    {
        return collect((array) ($this->settings($feedProfile)['forced_attribute_overrides'] ?? []))
            ->filter(static fn ($value, $key) => is_scalar($value) && is_scalar($key))
            ->mapWithKeys(static fn ($value, $key) => [Canonicalizer::normalizeKey((string) $key) => (string) $value])
            ->all();
    }

    public function overrideValue(FeedProfile $feedProfile, ?string $candidateValue): ?string
    {
        if ($candidateValue === null) {
            return null;
        }

        $key = Canonicalizer::normalizeKey($candidateValue);
        $overrides = collect((array) ($this->settings($feedProfile)['forced_value_overrides'] ?? []))
            ->filter(static fn ($value, $overrideKey) => is_scalar($value) && is_scalar($overrideKey))
            ->mapWithKeys(static fn ($value, $overrideKey) => [Canonicalizer::normalizeKey((string) $overrideKey) => (string) $value]);

        return $overrides->get($key);
    }

    /**
     * @param  array<string, string>  $mappedAttributes
     * @return array<string, string>
     */
    public function applyAttributeOverrides(FeedProfile $feedProfile, array $mappedAttributes): array
    {
        $overridden = $mappedAttributes;
        $forcedAttributes = $this->forcedAttributeOverrides($feedProfile);

        foreach ($overridden as $attributeCode => $value) {
            $overrideValue = $this->overrideValue($feedProfile, $value);

            if ($overrideValue !== null) {
                $overridden[$attributeCode] = $overrideValue;
            }
        }

        foreach ($forcedAttributes as $attributeCode => $value) {
            $overridden[$attributeCode] = $value;
        }

        return $overridden;
    }
}
