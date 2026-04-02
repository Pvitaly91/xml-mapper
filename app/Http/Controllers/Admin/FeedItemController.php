<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedItems\ManageFeedItemsAction;
use App\Actions\Admin\FeedItems\OverrideFeedItemAction;
use App\Contracts\Mappings\AttributeMappingServiceInterface;
use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Http\Requests\Admin\FeedItems\FeedItemBulkActionRequest;
use App\Http\Requests\Admin\FeedItems\FeedItemOverrideRequest;
use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceCategory;
use App\Models\ValidationError;
use App\Support\Canonicalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedItemController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        $query = FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($request->string('status')->toString(), fn ($builder, $status) => $builder->where('status', $status))
            ->when($request->filled('enabled'), fn ($builder) => $builder->where('is_enabled', $request->boolean('enabled')))
            ->when($request->integer('source_category_id'), fn ($builder, $categoryId) => $builder->whereHas('sourceProduct', fn ($innerQuery) => $innerQuery->where('source_category_id', $categoryId)))
            ->when($request->integer('mapped_category_id'), function ($builder, $categoryId) use ($feedProfile): void {
                $builder->whereHas('sourceProduct.sourceCategory.categoryMappings', function ($innerQuery) use ($feedProfile, $categoryId): void {
                    $innerQuery->where('feed_profile_id', $feedProfile->id)
                        ->where('kasta_category_id', $categoryId)
                        ->where('is_active', true);
                });
            })
            ->when($request->string('vendor')->toString(), fn ($builder, $vendor) => $builder->whereHas('sourceProduct', fn ($innerQuery) => $innerQuery->where('vendor', 'like', '%'.$vendor.'%')))
            ->when($request->string('article')->toString(), fn ($builder, $article) => $builder->whereHas('sourceProduct', fn ($innerQuery) => $innerQuery->where('article', 'like', '%'.$article.'%')))
            ->when($request->string('validation_code')->toString(), fn ($builder, $code) => $builder->whereHas('activeValidationErrors', fn ($innerQuery) => $innerQuery->where('code', $code)))
            ->when($request->string('search')->toString(), function ($builder, $search): void {
                $builder->where(function ($innerQuery) use ($search): void {
                    $innerQuery->whereHas('sourceProduct', fn ($productQuery) => $productQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('article', 'like', '%'.$search.'%'))
                        ->orWhereHas('sourceVariant', fn ($variantQuery) => $variantQuery
                            ->where('stable_offer_id', 'like', '%'.$search.'%')
                            ->orWhere('external_offer_id', 'like', '%'.$search.'%')
                            ->orWhere('title', 'like', '%'.$search.'%'));
                });
            });

        $items = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $mappingMap = CategoryMapping::query()
            ->with('kastaCategory')
            ->where('feed_profile_id', $feedProfile->id)
            ->where('is_active', true)
            ->whereIn('source_category_id', $items->getCollection()->pluck('sourceProduct.source_category_id')->filter()->unique())
            ->get()
            ->keyBy('source_category_id');

        return view('admin.feed-items.index', [
            'feedProfile' => $feedProfile,
            'items' => $items,
            'mappingMap' => $mappingMap,
            'sourceCategories' => SourceCategory::query()->where('source_connection_id', $feedProfile->source_connection_id)->orderBy('full_path')->get(),
            'mappedCategories' => CategoryMapping::query()->with('kastaCategory')->where('feed_profile_id', $feedProfile->id)->where('is_active', true)->get(),
            'validationCodes' => ValidationError::query()->where('feed_profile_id', $feedProfile->id)->distinct()->pluck('code'),
            'filters' => $request->only(['status', 'enabled', 'source_category_id', 'mapped_category_id', 'vendor', 'article', 'validation_code', 'search']),
        ]);
    }

    public function show(
        Request $request,
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        CategoryMappingServiceInterface $categoryMappingService,
        AttributeMappingServiceInterface $attributeMappingService
    ): View {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedItem->feed_profile_id === $feedProfile->id, 404);

        $feedItem->loadMissing(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors']);

        $mappedCategory = $categoryMappingService->getMappedCategory($feedProfile, $feedItem->sourceProduct?->sourceCategory);
        $resolvedAttributes = ($feedItem->sourceProduct !== null && $feedItem->sourceVariant !== null)
            ? $attributeMappingService->resolveMappedAttributes($feedProfile, $feedItem->sourceProduct, $feedItem->sourceVariant, $mappedCategory)
            : [];

        return view('admin.feed-items.show', [
            'feedProfile' => $feedProfile,
            'feedItem' => $feedItem,
            'mappedCategory' => $mappedCategory,
            'resolvedAttributes' => $resolvedAttributes,
            'attributeRows' => $this->attributeRows($feedProfile, $feedItem),
        ]);
    }

    public function bulkUpdate(FeedItemBulkActionRequest $request, FeedProfile $feedProfile, ManageFeedItemsAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $count = $action->handle(
            $feedProfile,
            $request->validated('feed_item_ids'),
            $request->validated('operation'),
            $request->validated('reason')
        );

        return back()->with('status', sprintf('Feed items updated: %d.', $count));
    }

    public function override(
        FeedItemOverrideRequest $request,
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        OverrideFeedItemAction $action
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedItem->feed_profile_id === $feedProfile->id, 404);

        $action->handle($feedProfile, $feedItem, [
            'is_enabled' => (bool) $request->boolean('is_enabled'),
            'excluded_reason' => $request->validated('excluded_reason'),
        ]);

        return back()->with('status', 'Feed item override saved.');
    }

    /**
     * @return array<int, array{source_attribute:string,source_value:?string,kasta_attribute:string,target_value:?string}>
     */
    private function attributeRows(FeedProfile $feedProfile, FeedItem $feedItem): array
    {
        if ($feedItem->sourceProduct === null || $feedItem->sourceVariant === null) {
            return [];
        }

        $mappings = AttributeMapping::query()
            ->with(['sourceAttribute', 'kastaAttribute', 'valueMappings.kastaAttributeValue'])
            ->where('feed_profile_id', $feedProfile->id)
            ->where(function ($query) use ($feedItem): void {
                $query->whereNull('source_category_id')
                    ->orWhere('source_category_id', $feedItem->sourceProduct->source_category_id);
            })
            ->orderBy('source_category_id')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($mappings as $mapping) {
            if ($mapping->sourceAttribute === null || $mapping->kastaAttribute === null) {
                continue;
            }

            $variantSnapshot = collect($feedItem->sourceVariant->attributes_snapshot ?? [])
                ->mapWithKeys(fn ($value, $key) => [Canonicalizer::normalizeKey((string) $key) => is_array($value) ? implode(', ', $value) : (string) $value]);
            $productSnapshot = collect($feedItem->sourceProduct->attributes_snapshot ?? [])
                ->mapWithKeys(fn ($value, $key) => [Canonicalizer::normalizeKey((string) $key) => is_array($value) ? implode(', ', $value) : (string) $value]);

            $sourceValue = $variantSnapshot->get(Canonicalizer::normalizeKey($mapping->sourceAttribute->code))
                ?? $variantSnapshot->get(Canonicalizer::normalizeKey($mapping->sourceAttribute->name))
                ?? $productSnapshot->get(Canonicalizer::normalizeKey($mapping->sourceAttribute->code))
                ?? $productSnapshot->get(Canonicalizer::normalizeKey($mapping->sourceAttribute->name));

            $normalized = Canonicalizer::normalizeText(mb_strtolower((string) $sourceValue));
            $valueMapping = $mapping->valueMappings->first(fn ($candidate) => $candidate->normalized_source_value === $normalized);

            $rows[] = [
                'source_attribute' => $mapping->sourceAttribute->name,
                'source_value' => Canonicalizer::normalizeText($sourceValue),
                'kasta_attribute' => $mapping->kastaAttribute->name,
                'target_value' => $valueMapping?->kastaAttributeValue?->value ?? $valueMapping?->target_value ?? $mapping->default_value,
            ];
        }

        return $rows;
    }
}
