<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedItems\ApplyFeedItemEnrichmentAction;
use App\Http\Requests\Admin\FeedItems\FeedItemEnrichmentApplyRequest;
use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedItemDiagnosticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedContentEnrichmentController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile, FeedItemDiagnosticsService $diagnosticsService): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        $generation = $request->integer('generation_id')
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail($request->integer('generation_id'))
            : $feedProfile->latestGeneration;
        $scope = $request->string('scope')->toString() ?: 'blocked';
        $items = FeedItem::query()
            ->with(['sourceProduct.sourceCategory', 'sourceProduct.variants', 'sourceVariant', 'mappingExceptions', 'activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->when($generation !== null, fn ($query) => $query->where('last_built_generation_id', $generation->id))
            ->when($scope === 'blocked', fn ($query) => $query->whereIn('status', array_merge(FeedItem::invalidStatuses(), [FeedItem::STATUS_EXCLUDED])))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
        $previews = $items->getCollection()
            ->mapWithKeys(function (FeedItem $feedItem) use ($feedProfile, $diagnosticsService): array {
                if ($feedItem->sourceProduct === null || $feedItem->sourceVariant === null) {
                    return [$feedItem->id => null];
                }

                return [$feedItem->id => $diagnosticsService->analyze(
                    $feedProfile,
                    $feedItem->sourceProduct,
                    $feedItem->sourceVariant,
                    $feedItem
                )];
            });

        return view('admin.feed-content-enrichment.index', [
            'feedProfile' => $feedProfile,
            'generation' => $generation,
            'items' => $items,
            'previews' => $previews,
            'filters' => [
                'scope' => $scope,
            ],
        ]);
    }

    public function apply(
        FeedItemEnrichmentApplyRequest $request,
        FeedProfile $feedProfile,
        ApplyFeedItemEnrichmentAction $action
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        $summary = $action->handle(
            $feedProfile,
            $request->validated('feed_item_ids'),
            $request->validated('reason'),
            $request->user()
        );

        return back()->with(
            'status',
            sprintf(
                'Enrichment overrides applied: %d, skipped: %d, manual-skipped: %d.',
                $summary['applied'],
                $summary['skipped'],
                $summary['manual_skipped']
            )
        );
    }
}
