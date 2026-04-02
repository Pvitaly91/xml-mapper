<?php

namespace App\Actions\Admin\Dashboard;

use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Models\SourceImport;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\ValidationError;
use App\Services\Ops\OpsStatusService;

class BuildDashboardMetricsAction
{
    public function __construct(
        private readonly OpsStatusService $opsStatusService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Shop $shop): array
    {
        $ops = $this->opsStatusService->snapshot($shop);

        return [
            'total_source_products' => SourceProduct::query()->where('shop_id', $shop->id)->count(),
            'total_source_variants' => SourceVariant::query()->where('shop_id', $shop->id)->count(),
            'total_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->count(),
            'ready_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->where('status', FeedItem::STATUS_READY)->count(),
            'invalid_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->where('status', FeedItem::STATUS_INVALID)->count(),
            'excluded_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->where('status', FeedItem::STATUS_EXCLUDED)->count(),
            'active_validation_errors' => ValidationError::query()->where('shop_id', $shop->id)->where('is_active', true)->count(),
            'last_sync' => $ops['last_successful_sync'],
            'last_build' => $ops['last_successful_build'],
            'last_publish' => $ops['last_successful_publish'],
            'active_feed_profiles' => FeedProfile::query()->where('shop_id', $shop->id)->where('status', FeedProfile::STATUS_ACTIVE)->count(),
            'ops' => $ops,
            'ops_status' => $this->opsStatusService->overallStatus($shop),
        ];
    }
}
