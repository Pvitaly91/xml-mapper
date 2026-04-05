<?php

namespace App\Services\Demo;

use App\Actions\Admin\Dictionaries\ImportKastaDictionariesAction;
use App\Actions\Admin\FeedItems\ApplyFeedItemEnrichmentAction;
use App\Actions\Admin\Mappings\ApplyAttributeMappingSuggestionsAction;
use App\Actions\Admin\Mappings\ApproveValueMappingSuggestionsAction;
use App\Actions\Admin\Mappings\RunCategoryAutomapAction;
use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Models\AttributeMapping;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Feeds\FeedReleaseReportService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Mappings\Automation\FeedItemMappingExceptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class FunctionalMerchantDemoService
{
    private const SHOP_SLUG = 'functional-demo-shop';
    private const USER_EMAIL = 'functional-operator@demo.test';
    private const SOURCE_CODE = 'functional-demo-prom';
    private const FEED_CODE = 'functional-demo-kasta';

    public function __construct(
        private readonly ImportKastaDictionariesAction $importDictionariesAction,
        private readonly SourceSyncWorkflowServiceInterface $sourceSyncWorkflow,
        private readonly RunCategoryAutomapAction $runCategoryAutomapAction,
        private readonly ApplyAttributeMappingSuggestionsAction $applyAttributeSuggestionsAction,
        private readonly ApproveValueMappingSuggestionsAction $approveValueSuggestionsAction,
        private readonly ApplyFeedItemEnrichmentAction $applyFeedItemEnrichmentAction,
        private readonly FeedItemMappingExceptionService $exceptionService,
        private readonly FeedBuildServiceInterface $feedBuildService,
        private readonly FeedReleaseService $releaseService,
        private readonly FeedReleaseReportService $reportService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $fresh = false): array
    {
        $this->ensureAllowedEnvironment();

        if ($fresh) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        $shop = $this->upsertShop();
        $user = $this->upsertOperator($shop);
        $this->ensureDictionaries($user);
        $connection = $this->upsertConnection($shop);
        $feedProfile = $this->upsertFeedProfile($connection, $user);
        $import = $this->sourceSyncWorkflow->run($connection->fresh(), false);

        $this->runCategoryAutomapAction->handle($feedProfile);
        $initialGeneration = $this->feedBuildService->build($feedProfile->fresh());
        $initialReport = $this->reportService->functionalXmlReport($feedProfile->fresh(), $initialGeneration->fresh());

        $sourceCategoryIds = $feedProfile->categoryMappings()
            ->where('is_active', true)
            ->pluck('source_category_id')
            ->all();

        foreach ($sourceCategoryIds as $sourceCategoryId) {
            $this->applyAttributeSuggestionsAction->handle($feedProfile, (int) $sourceCategoryId);
        }

        AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->orderBy('id')
            ->get()
            ->each(fn (AttributeMapping $mapping) => $this->approveValueSuggestionsAction->handle($mapping));

        $postMappingGeneration = $this->feedBuildService->build($feedProfile->fresh());
        $postMappingReport = $this->reportService->functionalXmlReport($feedProfile->fresh(), $postMappingGeneration->fresh());

        $feedItemIds = FeedItem::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('last_built_generation_id', $postMappingGeneration->id)
            ->pluck('id')
            ->all();
        $enrichmentSummary = $this->applyFeedItemEnrichmentAction->handle(
            $feedProfile,
            $feedItemIds,
            'Persist deterministic enrichment preview for the functional merchant demo.',
            $user
        );

        $blockedSneaker = FeedItem::query()
            ->with(['activeValidationErrors'])
            ->where('feed_profile_id', $feedProfile->id)
            ->where('last_built_generation_id', $postMappingGeneration->id)
            ->whereHas('activeValidationErrors', fn ($query) => $query->where('code', 'insufficient_images'))
            ->first();

        if (! $blockedSneaker instanceof FeedItem) {
            throw new RuntimeException('Functional demo expected one footwear item blocked by insufficient images.');
        }

        $this->exceptionService->syncContentOverrides(
            $feedProfile,
            $blockedSneaker,
            [
                'images' => [
                    'https://example.test/demo-shoe-1.jpg',
                    'https://example.test/demo-shoe-2.jpg',
                    'https://example.test/demo-shoe-3.jpg',
                ],
            ],
            'Add the missing footwear images required by the export contract.',
            $user,
            'manual'
        );

        $finalGeneration = $this->feedBuildService->build($feedProfile->fresh());
        $this->releaseService->markCandidate($finalGeneration->fresh(), $user, 'Functional demo candidate ready');
        $approvedGeneration = $this->releaseService->approve($finalGeneration->fresh(), $user, 'Functional demo approved for publish-ready QA');
        $finalReport = $this->reportService->functionalXmlReport($feedProfile->fresh(), $approvedGeneration->fresh());
        $summary = [
            'generated_at' => now()->toIso8601String(),
            'shop' => [
                'id' => $shop->id,
                'slug' => $shop->slug,
                'name' => $shop->name,
            ],
            'user' => [
                'email' => $user->email,
            ],
            'source_connection' => [
                'id' => $connection->id,
                'driver' => $connection->driver,
                'fixture_path' => $connection->source_url,
            ],
            'feed_profile' => [
                'id' => $feedProfile->id,
                'code' => $feedProfile->code,
            ],
            'import_id' => $import->id,
            'generations' => [
                'initial' => [
                    'id' => $initialGeneration->id,
                    'excluded_items' => data_get($initialReport, 'summary.excluded_items_count', 0),
                    'issues' => data_get($initialReport, 'summary.issues_count', 0),
                ],
                'post_mapping' => [
                    'id' => $postMappingGeneration->id,
                    'excluded_items' => data_get($postMappingReport, 'summary.excluded_items_count', 0),
                    'issues' => data_get($postMappingReport, 'summary.issues_count', 0),
                ],
                'final' => [
                    'id' => $approvedGeneration->id,
                    'release_status' => $approvedGeneration->release_status,
                    'artifact_path' => $approvedGeneration->file_path,
                    'included_items' => data_get($finalReport, 'summary.included_items_count', 0),
                    'excluded_items' => data_get($finalReport, 'summary.excluded_items_count', 0),
                    'publish_ready' => data_get($finalReport, 'summary.publish_ready', false),
                ],
            ],
            'enrichment_apply' => $enrichmentSummary,
            'report' => $finalReport['summary'],
        ];

        File::ensureDirectoryExists(dirname($this->summaryPath()));
        File::put($this->summaryPath(), json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return array_merge($summary, [
            'summary_path' => $this->summaryPath(),
        ]);
    }

    public function summaryPath(): string
    {
        return storage_path('app/demo/functional-export-summary.json');
    }

    private function ensureAllowedEnvironment(): void
    {
        if (! app()->environment(['local', 'testing', 'e2e'])) {
            throw new RuntimeException('demo:functional-export is only allowed in local, testing, or e2e environments.');
        }
    }

    private function upsertShop(): Shop
    {
        return Shop::query()->updateOrCreate(
            ['slug' => self::SHOP_SLUG],
            [
                'name' => 'Functional Demo Shop',
                'currency' => 'UAH',
                'locale' => 'uk',
                'timezone' => 'Europe/Kiev',
                'is_active' => true,
            ]
        );
    }

    private function upsertOperator(Shop $shop): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => self::USER_EMAIL],
            [
                'shop_id' => $shop->id,
                'name' => 'Functional Demo Operator',
                'password' => Hash::make('FunctionalDemoPass123!'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        ShopMembership::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'shop_id' => $shop->id,
            ],
            [
                'role' => ShopMembership::ROLE_SHOP_ADMIN,
                'status' => ShopMembership::STATUS_ACTIVE,
            ]
        );

        return $user->fresh();
    }

    private function ensureDictionaries(User $user): void
    {
        if (KastaCategory::query()->exists() && KastaAttribute::query()->exists() && KastaAttributeValue::query()->exists()) {
            return;
        }

        $this->importDictionariesAction->handle(null, $user->id);
    }

    private function upsertConnection(Shop $shop): SourceConnection
    {
        return SourceConnection::query()->updateOrCreate(
            [
                'shop_id' => $shop->id,
                'code' => self::SOURCE_CODE,
            ],
            [
                'name' => 'Functional Demo Prom YML',
                'driver' => SourceConnection::DRIVER_PROM_YML,
                'status' => SourceConnection::STATUS_ACTIVE,
                'source_url' => base_path('database/samples/functional-demo/prom-functional-demo.yml'),
                'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
                'last_sync_status' => SourceConnection::CHECK_STATUS_OK,
                'last_synced_at' => now(),
                'sync_interval_minutes' => 60,
            ]
        );
    }

    private function upsertFeedProfile(SourceConnection $connection, User $user): FeedProfile
    {
        return FeedProfile::query()->updateOrCreate(
            [
                'shop_id' => $connection->shop_id,
                'code' => self::FEED_CODE,
            ],
            [
                'source_connection_id' => $connection->id,
                'user_id' => $user->id,
                'name' => 'Functional Demo Kasta Feed',
                'status' => FeedProfile::STATUS_ACTIVE,
                'currency' => 'UAH',
                'language' => 'uk',
                'include_unavailable' => false,
                'auto_sync' => false,
                'auto_build' => false,
                'build_interval_minutes' => 60,
                'settings' => [
                    'publish_guard_enabled' => true,
                    'minimum_ready_items' => 1,
                    'maximum_invalid_ratio' => 0.2,
                    'block_publish_on_critical_conformance' => true,
                    'minimum_pictures' => 1,
                ],
            ]
        );
    }
}
