<?php

namespace Tests\Feature\Ops;

use App\Models\FeedItem;
use App\Models\PerformanceRun;
use App\Services\Feeds\FeedReleaseReportService;
use App\Services\Ops\PerformanceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class PerformanceScaleWorkflowTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('feed_mediator.storage_disk'));
        config([
            'feed_mediator.performance.scale.fixtures_directory' => storage_path('app/testing-scale-fixtures'),
        ]);

        File::deleteDirectory((string) config('feed_mediator.performance.scale.fixtures_directory'));
    }

    public function test_load_bootstrap_command_generates_scale_catalog_and_persists_run(): void
    {
        $this->artisan('ops:load-bootstrap --fresh --products=20 --variants-per-product=3')
            ->assertSuccessful();

        $run = PerformanceRun::query()
            ->with('stageRuns')
            ->where('run_type', PerformanceRun::TYPE_LOAD_BOOTSTRAP)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame(20, $run->dataset_products);
        $this->assertSame(60, $run->dataset_variants);
        $this->assertSame(PerformanceRun::STATUS_SUCCEEDED, $run->status);
        $this->assertDatabaseCount('source_products', 20);
        $this->assertDatabaseCount('source_variants', 60);
        $this->assertDatabaseHas('feed_profiles', ['code' => 'scale-main']);

        $shop = \App\Models\Shop::query()->where('slug', 'scale-catalog-20p-3v')->firstOrFail();
        $fixture = (array) data_get($shop->settings, 'scale_fixture', []);
        $this->assertFileExists((string) ($fixture['catalog_path'] ?? ''));
        $this->assertFileExists((string) ($fixture['feedback_csv_path'] ?? ''));
        $this->assertFileExists((string) ($fixture['feedback_json_path'] ?? ''));
    }

    public function test_scale_bootstrap_is_reproducible_without_duplicate_catalog_growth(): void
    {
        $service = app(PerformanceWorkflowService::class);

        $firstRun = $service->runLoadBootstrap(12, 2, true);
        $secondRun = $service->runLoadBootstrap(12, 2, false);

        $this->assertSame(PerformanceRun::STATUS_SUCCEEDED, $firstRun->status);
        $this->assertSame(PerformanceRun::STATUS_SUCCEEDED, $secondRun->status);
        $this->assertDatabaseCount('shops', 1);
        $this->assertDatabaseCount('feed_profiles', 1);
        $this->assertDatabaseCount('source_products', 12);
        $this->assertDatabaseCount('source_variants', 24);
    }

    public function test_benchmark_command_persists_stage_metrics_and_support_commands_work(): void
    {
        $service = app(PerformanceWorkflowService::class);
        $bootstrapRun = $service->runLoadBootstrap(10, 2, true);
        $feedProfileId = (int) $bootstrapRun->feed_profile_id;

        $this->artisan('ops:benchmark-run '.$feedProfileId.' --stages=sync,normalize,build,reconciliation,report_generation --label=scale-smoke')
            ->assertSuccessful();
        $this->artisan('ops:benchmark-compare --profile='.$feedProfileId)
            ->assertSuccessful();
        $this->artisan('ops:queue-health')
            ->assertSuccessful();
        $this->artisan('ops:report-heavy-queries')
            ->assertSuccessful();

        $benchmarkRun = PerformanceRun::query()
            ->with('stageRuns')
            ->where('run_type', PerformanceRun::TYPE_BENCHMARK)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($feedProfileId, $benchmarkRun->feed_profile_id);
        $this->assertSame(5, $benchmarkRun->stageRuns->count());
        $this->assertContains($benchmarkRun->budget_status, ['within_budget', 'warning', 'critical']);
        $this->assertNotNull($benchmarkRun->duration_ms);
    }

    public function test_invalid_items_csv_streaming_path_preserves_content(): void
    {
        $service = app(PerformanceWorkflowService::class);
        $bootstrapRun = $service->runLoadBootstrap(10, 2, true);
        $feedProfile = \App\Models\FeedProfile::findOrFail((int) $bootstrapRun->feed_profile_id);
        $generation = $feedProfile->latestGeneration()->firstOrFail();

        $feedProfile->items()
            ->orderBy('id')
            ->limit(6)
            ->update(['status' => FeedItem::STATUS_INVALID_MAPPING]);

        $csv = app(FeedReleaseReportService::class)->invalidItemsCsv($feedProfile, $generation);
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($csv))));

        $this->assertGreaterThanOrEqual(7, count($lines));
        $this->assertStringContainsString('item_id,source_product_id,source_variant_id', $lines[0]);
    }

    public function test_performance_center_renders_details_and_report_download(): void
    {
        $admin = $this->createAdminUser();
        $service = app(PerformanceWorkflowService::class);
        $loadRun = $service->runLoadBootstrap(8, 2, true, $admin);
        $shop = \App\Models\Shop::findOrFail((int) $loadRun->shop_id);
        $feedProfile = \App\Models\FeedProfile::findOrFail((int) $loadRun->feed_profile_id);
        $benchmarkRun = $service->runBenchmark($feedProfile, ['build', 'reconciliation'], $admin, 'ui-smoke');

        $this->actingAs($admin)
            ->withSession(['admin_shop_id' => $shop->id])
            ->get(route('admin.performance.index', ['feed_profile_id' => $feedProfile->id]))
            ->assertOk()
            ->assertSee('Performance Center');

        $this->actingAs($admin)
            ->withSession(['admin_shop_id' => $shop->id])
            ->get(route('admin.performance.show', $benchmarkRun))
            ->assertOk()
            ->assertSee((string) $benchmarkRun->id);

        $this->actingAs($admin)
            ->withSession(['admin_shop_id' => $shop->id])
            ->get(route('admin.performance.report', $benchmarkRun))
            ->assertOk();
    }

    public function test_prune_performance_runs_command_removes_old_records(): void
    {
        $run = PerformanceRun::create([
            'run_type' => PerformanceRun::TYPE_BENCHMARK,
            'status' => PerformanceRun::STATUS_SUCCEEDED,
            'budget_status' => PerformanceRun::BUDGET_WITHIN,
            'started_at' => now()->subDays(40),
            'finished_at' => now()->subDays(40),
        ]);

        $this->artisan('ops:prune-performance-runs --days=30')
            ->assertSuccessful();

        $this->assertDatabaseMissing('performance_runs', ['id' => $run->id]);
    }
}
