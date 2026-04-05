<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedItems\ManageFeedItemsAction;
use App\Actions\Admin\FeedItems\OverrideFeedItemAction;
use App\Actions\Admin\FeedItems\SaveFeedItemContentOverrideAction;
use App\Http\Requests\Admin\FeedItems\FeedItemBulkActionRequest;
use App\Http\Requests\Admin\FeedItems\FeedItemContentOverrideRequest;
use App\Http\Requests\Admin\FeedItems\FeedItemOverrideRequest;
use App\Models\CategoryMapping;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceCategory;
use App\Models\ValidationError;
use App\Services\Feeds\FeedItemDiagnosticsService;
use App\Services\Feeds\KastaExportXmlService;
use App\Services\Mappings\Automation\FeedItemMappingExceptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedItemController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        $diagnosticCodes = $this->diagnosticFilterCodes($request->string('diagnostic')->toString());
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
            ->when($diagnosticCodes !== [], fn ($builder) => $builder->whereHas('activeValidationErrors', fn ($innerQuery) => $innerQuery->whereIn('code', $diagnosticCodes)))
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
            'diagnosticOptions' => $this->diagnosticOptions(),
            'filters' => $request->only(['status', 'enabled', 'source_category_id', 'mapped_category_id', 'vendor', 'article', 'validation_code', 'diagnostic', 'search']),
        ]);
    }

    public function show(
        Request $request,
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        FeedItemDiagnosticsService $diagnosticsService,
        KastaExportXmlService $xmlService
    ): View {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedItem->feed_profile_id === $feedProfile->id, 404);

        $feedItem->loadMissing(['sourceProduct.sourceCategory', 'sourceVariant', 'activeValidationErrors']);

        $diagnostics = ($feedItem->sourceProduct !== null && $feedItem->sourceVariant !== null)
            ? $diagnosticsService->analyze($feedProfile, $feedItem->sourceProduct, $feedItem->sourceVariant, $feedItem)
            : null;
        $xmlPreview = ($diagnostics !== null && ! blank($diagnostics['normalized_export_snapshot']['category_id'] ?? null))
            ? $xmlService->renderOfferFragment($diagnostics['normalized_export_snapshot'])
            : null;

        return view('admin.feed-items.show', [
            'feedProfile' => $feedProfile,
            'feedItem' => $feedItem,
            'diagnostics' => $diagnostics,
            'mappedCategory' => $diagnostics['mapped_category'] ?? null,
            'resolvedAttributes' => $diagnostics['mapped_attributes'] ?? [],
            'attributeRows' => $diagnostics['attribute_rows'] ?? [],
            'exceptionRows' => $diagnostics['exception_rows'] ?? [],
            'kastaCategories' => KastaCategory::query()->where('is_active', true)->orderBy('full_path')->get(),
            'xmlPreview' => $xmlPreview,
        ]);
    }

    public function updateContentOverride(
        FeedItemContentOverrideRequest $request,
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        SaveFeedItemContentOverrideAction $action
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedItem->feed_profile_id === $feedProfile->id, 404);

        $payload = $request->validated();
        $payload['images'] = collect(preg_split('/\r\n|\r|\n/', (string) ($payload['images'] ?? '')) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $action->handle($feedProfile, $feedItem, $payload, $request->user());

        return back()->with('status', 'Feed item content override saved.');
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

    public function storeCategoryException(
        Request $request,
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        FeedItemMappingExceptionService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedItem->feed_profile_id === $feedProfile->id, 404);

        $validated = $request->validate([
            'kasta_category_id' => ['required', 'integer', 'exists:kasta_categories,id'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $category = KastaCategory::query()->findOrFail($validated['kasta_category_id']);
        $service->upsertCategoryException(
            $feedProfile,
            $feedItem,
            $category->id,
            $category->full_path ?: $category->name,
            $validated['reason'],
            $request->user()
        );

        return back()->with('status', 'Feed item category exception saved.');
    }

    public function storeAttributeException(
        Request $request,
        FeedProfile $feedProfile,
        FeedItem $feedItem,
        FeedItemMappingExceptionService $service
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($feedItem->feed_profile_id === $feedProfile->id, 404);

        $validated = $request->validate([
            'attribute_code' => ['required', 'string', 'max:120'],
            'target_value' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $service->upsertAttributeException(
            $feedProfile,
            $feedItem,
            $validated['attribute_code'],
            $validated['target_value'],
            $validated['reason'],
            $request->user()
        );

        return back()->with('status', 'Feed item attribute exception saved.');
    }

    /**
     * @return array<string, string>
     */
    private function diagnosticOptions(): array
    {
        return [
            'missing_category_mapping' => 'Missing category mapping',
            'missing_attribute_mapping' => 'Missing attribute mapping',
            'missing_value_mapping' => 'Missing value mapping',
            'missing_required_attribute' => 'Missing required source value',
            'invalid_color_size' => 'Invalid color or size',
            'missing_images' => 'Missing images',
        ];
    }

    /**
     * @return list<string>
     */
    private function diagnosticFilterCodes(?string $diagnostic): array
    {
        return match ($diagnostic) {
            'missing_category_mapping' => [ValidationError::CODE_MISSING_CATEGORY_MAPPING],
            'missing_attribute_mapping' => [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_MAPPING],
            'missing_value_mapping' => [ValidationError::CODE_MISSING_VALUE_MAPPING],
            'missing_required_attribute' => [ValidationError::CODE_MISSING_REQUIRED_ATTRIBUTE_VALUE],
            'invalid_color_size' => [ValidationError::CODE_INVALID_COLOR, ValidationError::CODE_INVALID_SIZE],
            'missing_images' => [ValidationError::CODE_MISSING_PHOTO],
            default => [],
        };
    }
}
