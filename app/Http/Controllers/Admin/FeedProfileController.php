<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeedProfiles\UpsertFeedProfileAction;
use App\Http\Requests\Admin\FeedProfiles\FeedProfileRequest;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceConnection;
use App\Models\ValidationError;
use App\Services\Feeds\FeedHypercareService;
use App\Services\Feeds\FeedPilotReadinessService;
use App\Services\Promotion\PromotionStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedProfileController extends AdminController
{
    public function index(Request $request): View
    {
        $shop = $this->adminShop($request);

        $profiles = FeedProfile::query()
            ->with(['sourceConnection', 'publishedGeneration', 'latestGeneration'])
            ->where('shop_id', $shop->id)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->integer('source_connection_id'), fn ($query, $sourceConnectionId) => $query->where('source_connection_id', $sourceConnectionId))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.feed-profiles.index', [
            'profiles' => $profiles,
            'sourceConnections' => SourceConnection::query()->where('shop_id', $shop->id)->orderBy('name')->get(),
            'filters' => $request->only(['status', 'source_connection_id', 'search']),
        ]);
    }

    public function create(Request $request): View
    {
        $shop = $this->adminShop($request);

        return view('admin.feed-profiles.form', [
            'feedProfile' => new FeedProfile,
            'sourceConnections' => SourceConnection::query()->where('shop_id', $shop->id)->orderBy('name')->get(),
            'pageTitle' => 'Create Feed Profile',
        ]);
    }

    public function store(FeedProfileRequest $request, UpsertFeedProfileAction $action): RedirectResponse
    {
        $feedProfile = $action->handle($request->user(), $request->validated());

        return redirect()
            ->route('admin.feed-profiles.show', $feedProfile)
            ->with('status', 'Feed profile created.');
    }

    public function show(
        Request $request,
        FeedProfile $feedProfile,
        FeedPilotReadinessService $pilotReadinessService,
        FeedHypercareService $hypercareService,
        PromotionStatusService $promotionStatusService,
    ): View
    {
        $this->ensureShopOwned($request, $feedProfile);
        $feedProfile->load(['sourceConnection', 'publishedGeneration', 'latestGeneration']);
        $pilotReadiness = $pilotReadinessService->summarize($feedProfile);

        return view('admin.feed-profiles.show', [
            'feedProfile' => $feedProfile,
            'recentGenerations' => $feedProfile->generations()->latest('id')->limit(10)->get(),
            'feedItemStats' => [
                'total' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->count(),
                'ready' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_READY)->count(),
                'published' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_PUBLISHED)->count(),
                'invalid' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->whereIn('status', FeedItem::invalidStatuses())->count(),
                'excluded' => FeedItem::query()->where('feed_profile_id', $feedProfile->id)->where('status', FeedItem::STATUS_EXCLUDED)->count(),
            ],
            'activeValidationErrors' => ValidationError::query()
                ->where('feed_profile_id', $feedProfile->id)
                ->where('is_active', true)
                ->count(),
            'publicFeedUrl' => $feedProfile->published_path ? route('feeds.public', $feedProfile->public_token) : null,
            'pilotReadiness' => $pilotReadiness,
            'hypercareSummary' => $hypercareService->summarize($feedProfile),
            'promotionStatus' => $promotionStatusService->summarize($feedProfile),
        ]);
    }

    public function edit(Request $request, FeedProfile $feedProfile): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.feed-profiles.form', [
            'feedProfile' => $feedProfile,
            'sourceConnections' => SourceConnection::query()->where('shop_id', $request->user()->shop_id)->orderBy('name')->get(),
            'pageTitle' => 'Edit Feed Profile',
        ]);
    }

    public function update(FeedProfileRequest $request, FeedProfile $feedProfile, UpsertFeedProfileAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $profile = $action->handle($request->user(), $request->validated(), $feedProfile);

        return redirect()
            ->route('admin.feed-profiles.show', $profile)
            ->with('status', 'Feed profile updated.');
    }
}
