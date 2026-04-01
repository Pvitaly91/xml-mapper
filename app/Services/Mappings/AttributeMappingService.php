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
    ) {
    }

    public function resolveMappedAttributes(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?KastaCategory $kastaCategory = null
    ): array {
        $mappings = $this->resolveMappings($feedProfile, $product, $kastaCategory);
        $resolved = [];

        foreach ($mappings as $mapping) {
            $sourceValue = $this->extractSourceValue($mapping->sourceAttribute, $product, $variant, $mapping->use_variant_value);
            $targetValue = $this->valueMappingService->mapValue($mapping, $sourceValue);

            if ($targetValue === null || $mapping->kastaAttribute === null) {
                continue;
            }

            $resolved[$mapping->kastaAttribute->code] = $targetValue;
        }

        return $resolved;
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
