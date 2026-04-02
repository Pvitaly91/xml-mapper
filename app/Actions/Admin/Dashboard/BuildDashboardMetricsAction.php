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

class BuildDashboardMetricsAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Shop $shop): array
    {
        return [
            'total_source_products' => SourceProduct::query()->where('shop_id', $shop->id)->count(),
            'total_source_variants' => SourceVariant::query()->where('shop_id', $shop->id)->count(),
            'total_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->count(),
            'ready_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->where('status', FeedItem::STATUS_READY)->count(),
            'invalid_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->where('status', FeedItem::STATUS_INVALID)->count(),
            'excluded_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->where('status', FeedItem::STATUS_EXCLUDED)->count(),
            'active_validation_errors' => ValidationError::query()->where('shop_id', $shop->id)->where('is_active', true)->count(),
            'last_sync' => SourceImport::query()->where('shop_id', $shop->id)->latest('finished_at')->first(),
            'last_build' => FeedGeneration::query()->where('shop_id', $shop->id)->whereNotNull('built_at')->latest('built_at')->first(),
            'last_publish' => FeedGeneration::query()->where('shop_id', $shop->id)->whereNotNull('published_at')->latest('published_at')->first(),
            'active_feed_profiles' => FeedProfile::query()->where('shop_id', $shop->id)->where('status', FeedProfile::STATUS_ACTIVE)->count(),
        ];
    }
}
