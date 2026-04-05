<?php

namespace App\Services\Feeds;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\FeedItemMappingException;
use App\Models\SizeGrid;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Support\Canonicalizer;
use Illuminate\Support\Collection;

class VariantFamilyService
{
    /**
     * @var array<string, array<int, array{color:?string,size:?string}>>
     */
    private array $siblingOverrideCache = [];

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    public function buildContext(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        array $content,
        array $contract
    ): array {
        $siblings = $this->siblings($product)
            ->filter(fn (SourceVariant $candidate) => $candidate->is_enabled)
            ->values();

        $familyKey = Canonicalizer::fingerprint([
            'group_key' => $product->group_key,
            'category' => $contract['category_external_id'] ?? null,
            'vendor' => Canonicalizer::normalizeKey((string) ($content['vendor'] ?? '')),
            'article' => Canonicalizer::normalizeKey((string) ($content['article'] ?? '')),
        ]);

        $currentColorKey = Canonicalizer::normalizeKey((string) ($content['color'] ?? ''));
        $currentSizeKey = Canonicalizer::normalizeKey((string) ($content['size'] ?? ''));
        $siblingOverrides = $this->siblingOverrides($feedProfile, $product);
        $duplicateVariantIds = $siblings
            ->filter(function (SourceVariant $candidate) use ($variant, $currentColorKey, $currentSizeKey, $siblingOverrides): bool {
                if ($candidate->id === $variant->id) {
                    return false;
                }

                $candidateColorKey = Canonicalizer::normalizeKey((string) (($siblingOverrides[$candidate->id]['color'] ?? null)
                    ?: $candidate->color
                    ?: Canonicalizer::firstMatchingValue($candidate->attributes_snapshot ?? [], config('feed_mediator.normalization.color_keys'))
                    ?: ''));
                $candidateSizeKey = Canonicalizer::normalizeKey((string) (($siblingOverrides[$candidate->id]['size'] ?? null)
                    ?: $candidate->size
                    ?: Canonicalizer::firstMatchingValue($candidate->attributes_snapshot ?? [], config('feed_mediator.normalization.size_keys'))
                    ?: ''));

                return $candidateColorKey === $currentColorKey && $candidateSizeKey === $currentSizeKey;
            })
            ->pluck('id')
            ->values()
            ->all();

        $sizeGrid = $this->resolveSizeGrid(
            $feedProfile,
            Canonicalizer::normalizeText((string) ($content['size'] ?? '')),
            $contract
        );

        return [
            'family_key' => $familyKey,
            'family_scope' => $siblings->count() > 1 ? 'variant_family' : 'single_variant',
            'siblings_total' => $siblings->count(),
            'shared_fields' => [
                'vendor' => $content['vendor'] ?? null,
                'article' => $content['article'] ?? null,
                'category_external_id' => $contract['category_external_id'] ?? null,
                'base_product_name' => $product->name,
            ],
            'variant_fields' => [
                'stable_offer_id' => $variant->stable_offer_id,
                'color' => $content['color'] ?? null,
                'size' => $content['size'] ?? null,
            ],
            'duplicate_variant_ids' => $duplicateVariantIds,
            'has_duplicate_axes' => $duplicateVariantIds !== [],
            'size_grid_code' => $sizeGrid['code'],
            'size_grid_name' => $sizeGrid['name'],
            'size_grid_supported' => $sizeGrid['supported'],
            'size_grid_required' => (bool) ($contract['requires_size_grid'] ?? false),
            'size_grid_candidates' => $sizeGrid['candidates'],
        ];
    }

    /**
     * @return Collection<int, SourceVariant>
     */
    private function siblings(SourceProduct $product): Collection
    {
        if ($product->relationLoaded('variants')) {
            return $product->variants;
        }

        return SourceVariant::query()
            ->where('source_product_id', $product->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array{color:?string,size:?string}>
     */
    private function siblingOverrides(FeedProfile $feedProfile, SourceProduct $product): array
    {
        $cacheKey = $feedProfile->id.':'.$product->id;

        if (array_key_exists($cacheKey, $this->siblingOverrideCache)) {
            return $this->siblingOverrideCache[$cacheKey];
        }

        $overrides = FeedItem::query()
            ->with('mappingExceptions')
            ->where('feed_profile_id', $feedProfile->id)
            ->whereIn('source_variant_id', $this->siblings($product)->pluck('id'))
            ->get()
            ->mapWithKeys(function (FeedItem $feedItem): array {
                $fields = [];

                foreach ($feedItem->mappingExceptions as $exception) {
                    if (
                        ! $exception->is_active
                        || $exception->exception_type !== FeedItemMappingException::TYPE_CONTENT_FIELD
                        || ! in_array($exception->target_key, ['color', 'size'], true)
                    ) {
                        continue;
                    }

                    $fields[$exception->target_key] = Canonicalizer::normalizeText($exception->target_value);
                }

                return [$feedItem->source_variant_id => [
                    'color' => $fields['color'] ?? null,
                    'size' => $fields['size'] ?? null,
                ]];
            })
            ->all();

        $this->siblingOverrideCache[$cacheKey] = $overrides;

        return $overrides;
    }

    /**
     * @return array{code:?string,name:?string,supported:bool,candidates:list<string>}
     */
    private function resolveSizeGrid(FeedProfile $feedProfile, ?string $size, array $contract): array
    {
        if ($size === null) {
            return [
                'code' => null,
                'name' => null,
                'supported' => false,
                'candidates' => [],
            ];
        }

        $preferredCodes = array_values(array_unique(array_filter([
            $contract['size_grid_code_hint'] ?? null,
            preg_match('/^\d+$/', $size) === 1 ? 'adult-eu-shoes' : 'adult-alpha',
        ])));

        $grids = SizeGrid::query()
            ->where('is_active', true)
            ->where(function ($query) use ($feedProfile): void {
                $query->whereNull('shop_id')
                    ->orWhere('shop_id', $feedProfile->shop_id);
            })
            ->orderByRaw('CASE WHEN shop_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('code')
            ->get();

        foreach ($preferredCodes as $code) {
            $grid = $grids->first(fn (SizeGrid $candidate) => $candidate->code === $code);

            if (! $grid instanceof SizeGrid) {
                continue;
            }

            if ($this->gridSupports($grid, $size)) {
                return [
                    'code' => $grid->code,
                    'name' => $grid->name,
                    'supported' => true,
                    'candidates' => $preferredCodes,
                ];
            }
        }

        $fallback = $grids->first(fn (SizeGrid $grid) => $this->gridSupports($grid, $size));

        return [
            'code' => $fallback?->code,
            'name' => $fallback?->name,
            'supported' => $fallback instanceof SizeGrid,
            'candidates' => $preferredCodes,
        ];
    }

    private function gridSupports(SizeGrid $grid, string $size): bool
    {
        $labels = collect((array) data_get($grid->schema, 'labels', []))
            ->map(fn ($label) => Canonicalizer::normalizeText(mb_strtoupper((string) $label)))
            ->filter()
            ->values()
            ->all();

        return in_array(Canonicalizer::normalizeText(mb_strtoupper($size)), $labels, true);
    }
}
