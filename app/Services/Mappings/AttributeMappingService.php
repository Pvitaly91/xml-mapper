<?php

namespace App\Services\Mappings;

use App\Contracts\Mappings\AttributeMappingServiceInterface;
use App\Contracts\Mappings\ValueMappingServiceInterface;
use App\Models\AttributeMapping;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaCategory;
use App\Models\SourceAttribute;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Support\Canonicalizer;
use Illuminate\Support\Collection;

class AttributeMappingService implements AttributeMappingServiceInterface
{
    public function __construct(
        private readonly ValueMappingServiceInterface $valueMappingService,
    ) {}

    public function resolveMappedAttributes(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?KastaCategory $kastaCategory = null
    ): array {
        return $this->resolveMappingRows($feedProfile, $product, $variant, $kastaCategory)
            ->filter(fn (array $row) => $row['mapped_value'] !== null)
            ->mapWithKeys(fn (array $row) => [$row['kasta_attribute_code'] => $row['mapped_value']])
            ->all();
    }

    public function missingRequiredMappings(
        FeedProfile $feedProfile,
        SourceProduct $product,
        ?KastaCategory $kastaCategory = null
    ): array {
        if ($kastaCategory === null) {
            return [];
        }

        $requiredAttributes = KastaAttribute::query()
            ->where('kasta_category_id', $kastaCategory->id)
            ->where('is_required', true)
            ->pluck('code', 'id');

        $mappedAttributeIds = $this->resolveMappings($feedProfile, $product, $kastaCategory)
            ->pluck('kasta_attribute_id')
            ->filter()
            ->all();

        return $requiredAttributes
            ->reject(fn (string $code, int $id) => in_array($id, $mappedAttributeIds, true))
            ->values()
            ->all();
    }

    public function resolveMappingRows(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?KastaCategory $kastaCategory = null
    ): Collection {
        return $this->resolveMappings($feedProfile, $product, $kastaCategory)
            ->map(function (AttributeMapping $mapping) use ($product, $variant): ?array {
                if ($mapping->sourceAttribute === null || $mapping->kastaAttribute === null) {
                    return null;
                }

                $sourceValue = $this->extractSourceValue($mapping->sourceAttribute, $product, $variant, $mapping->use_variant_value);
                $resolution = $this->valueMappingService->resolveValue($mapping, $sourceValue);

                return [
                    'mapping' => $mapping,
                    'source_attribute_name' => $mapping->sourceAttribute->name,
                    'source_attribute_code' => $mapping->sourceAttribute->code,
                    'source_value' => $resolution['source_value'],
                    'kasta_attribute_name' => $mapping->kastaAttribute->name,
                    'kasta_attribute_code' => $mapping->kastaAttribute->code,
                    'kasta_attribute_id' => $mapping->kastaAttribute->id,
                    'mapped_value' => $resolution['mapped_value'],
                    'resolution' => $resolution['resolution'],
                    'allows_custom_value' => (bool) $mapping->kastaAttribute->allows_custom_value,
                    'default_value' => $mapping->default_value,
                    'use_variant_value' => (bool) $mapping->use_variant_value,
                    'has_value_mapping' => $mapping->valueMappings->isNotEmpty(),
                    'used_value_mapping' => $resolution['used_value_mapping'],
                    'used_default' => $resolution['used_default'],
                    'used_custom_value' => $resolution['used_custom_value'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, AttributeMapping>
     */
    private function resolveMappings(FeedProfile $feedProfile, SourceProduct $product, ?KastaCategory $kastaCategory = null): Collection
    {
        return AttributeMapping::query()
            ->with(['sourceAttribute', 'kastaAttribute', 'valueMappings.kastaAttributeValue'])
            ->where('feed_profile_id', $feedProfile->id)
            ->where(function ($query) use ($product): void {
                $query->whereNull('source_category_id');

                if ($product->source_category_id !== null) {
                    $query->orWhere('source_category_id', $product->source_category_id);
                }
            })
            ->when($kastaCategory !== null, function ($query) use ($kastaCategory): void {
                $query->where(function ($innerQuery) use ($kastaCategory): void {
                    $innerQuery->whereNull('kasta_category_id')
                        ->orWhere('kasta_category_id', $kastaCategory->id);
                });
            })
            ->get()
            ->sortByDesc(fn (AttributeMapping $mapping) => $mapping->source_category_id === $product->source_category_id ? 1 : 0)
            ->unique('kasta_attribute_id')
            ->values();
    }

    private function extractSourceValue(SourceAttribute $sourceAttribute, SourceProduct $product, SourceVariant $variant, bool $useVariantValue): ?string
    {
        $variantValue = $useVariantValue ? $this->snapshotValue($variant->attributes_snapshot ?? [], $sourceAttribute) : null;
        $productValue = $this->snapshotValue($product->attributes_snapshot ?? [], $sourceAttribute);

        return $variantValue ?? $productValue;
    }

    private function snapshotValue(array $snapshot, SourceAttribute $sourceAttribute): ?string
    {
        $normalizedSnapshot = collect($snapshot)->mapWithKeys(
            fn ($value, $key) => [Canonicalizer::normalizeKey((string) $key) => is_array($value) ? implode(', ', $value) : $value]
        );

        $value = $normalizedSnapshot->get(Canonicalizer::normalizeKey($sourceAttribute->code))
            ?? $normalizedSnapshot->get(Canonicalizer::normalizeKey($sourceAttribute->name));

        return Canonicalizer::normalizeText(is_scalar($value) ? (string) $value : null);
    }
}
