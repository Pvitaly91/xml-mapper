<?php

namespace App\Actions\Admin\Dashboard;

use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Models\ValidationError;
use App\Services\Ops\EnvironmentContextService;
use App\Services\Ops\OpsMaintenanceStatusService;
use App\Services\Ops\PerformanceCenterService;
use App\Services\Ops\OpsStatusService;
use App\Services\Ops\SloSummaryService;
use App\Services\Setup\DatabaseSetupInspector;

class BuildDashboardMetricsAction
{
    public function __construct(
        private readonly OpsStatusService $opsStatusService,
        private readonly OpsMaintenanceStatusService $opsMaintenanceStatusService,
        private readonly DatabaseSetupInspector $databaseSetupInspector,
        private readonly EnvironmentContextService $environmentContextService,
        private readonly SloSummaryService $sloSummaryService,
        private readonly PerformanceCenterService $performanceCenterService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(?Shop $shop): array
    {
        $schema = $this->databaseSetupInspector->dashboardReport();
        $ops = $this->opsStatusService->snapshot($shop);
        $maintenance = $this->opsMaintenanceStatusService->summarize($shop);
        $environment = $this->environmentContextService->summary();
        $slo = $this->sloSummaryService->summarize($shop);
        $performance = $this->performanceCenterService->summary($shop);

        if (! $schema['schema_ready'] || $shop === null) {
            return [
                'total_source_products' => 0,
                'total_source_variants' => 0,
                'total_feed_items' => 0,
                'ready_feed_items' => 0,
                'invalid_feed_items' => 0,
                'excluded_feed_items' => 0,
                'active_validation_errors' => 0,
                'last_sync' => $ops['last_successful_sync'],
                'last_build' => $ops['last_successful_build'],
                'last_publish' => $ops['last_successful_publish'],
                'active_feed_profiles' => 0,
                'ops' => $ops,
                'maintenance' => $maintenance,
                'environment' => $environment,
                'slo' => $slo,
                'performance' => $performance,
                'ops_status' => 'setup_required',
                'schema' => $schema,
                'setup_required' => $schema['setup_required'] || ! $schema['database_connected'],
                'missing_tables' => $schema['missing_tables'],
            ];
        }

        return [
            'total_source_products' => SourceProduct::query()->where('shop_id', $shop->id)->count(),
            'total_source_variants' => SourceVariant::query()->where('shop_id', $shop->id)->count(),
            'total_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->count(),
            'ready_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->whereIn('status', FeedItem::exportableStatuses())->count(),
            'invalid_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->whereIn('status', FeedItem::invalidStatuses())->count(),
            'excluded_feed_items' => FeedItem::query()->where('shop_id', $shop->id)->where('status', FeedItem::STATUS_EXCLUDED)->count(),
            'active_validation_errors' => ValidationError::query()->where('shop_id', $shop->id)->where('is_active', true)->count(),
            'last_sync' => $ops['last_successful_sync'],
            'last_build' => $ops['last_successful_build'],
            'last_publish' => $ops['last_successful_publish'],
            'active_feed_profiles' => FeedProfile::query()->where('shop_id', $shop->id)->where('status', FeedProfile::STATUS_ACTIVE)->count(),
            'ops' => $ops,
            'maintenance' => $maintenance,
            'environment' => $environment,
            'slo' => $slo,
            'performance' => $performance,
            'ops_status' => $this->opsStatusService->overallStatus($shop),
            'schema' => $schema,
            'setup_required' => false,
            'missing_tables' => [],
        ];
    }
}
