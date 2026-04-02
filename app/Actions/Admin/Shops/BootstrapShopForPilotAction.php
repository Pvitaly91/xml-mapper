<?php

namespace App\Actions\Admin\Shops;

use App\Actions\Admin\Dictionaries\ImportKastaDictionariesAction;
use App\Actions\Admin\Mappings\ApplyAttributeMappingSuggestionsAction;
use App\Actions\Admin\Mappings\ApproveValueMappingSuggestionsAction;
use App\Actions\Admin\Mappings\RunCategoryAutomapAction;
use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Models\AttributeMapping;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\Shop;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\User;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Shops\ShopOnboardingService;
use App\Services\Shops\ShopOnboardingStateService;
use RuntimeException;

class BootstrapShopForPilotAction
{
    public function __construct(
        private readonly ImportKastaDictionariesAction $importDictionariesAction,
        private readonly RunCategoryAutomapAction $runCategoryAutomapAction,
        private readonly ApplyAttributeMappingSuggestionsAction $applyAttributeSuggestionsAction,
        private readonly ApproveValueMappingSuggestionsAction $approveValueSuggestionsAction,
        private readonly FeedBuildServiceInterface $feedBuildService,
        private readonly FeedReleaseService $feedReleaseService,
        private readonly SourceSyncWorkflowServiceInterface $sourceSyncWorkflow,
        private readonly ShopOnboardingStateService $stateService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(User $user, bool $runSync = false, bool $buildCandidate = true): array
    {
        $user = $user->fresh() ?? $user;
        $shop = $this->requireShop($user);

        $dictionarySummary = $this->ensureDictionaries($user);
        $feedProfile = $this->ensureDefaultFeedProfile($user);
        $syncImport = $runSync ? $this->runFirstSync($user) : null;
        $mappingSummary = $this->applyInitialMappings($user, $feedProfile);
        $generation = $buildCandidate ? $this->buildReleaseCandidate($user, $feedProfile) : null;

        return [
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'dictionary_summary' => $dictionarySummary,
            'sync_import_id' => $syncImport?->id,
            'mapping_summary' => $mappingSummary,
            'generation_id' => $generation?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureDictionaries(User $user): array
    {
        $user = $user->fresh() ?? $user;
        if ($this->dictionariesImported()) {
            $this->stateService->markStepCompleted($user, ShopOnboardingService::STEP_DICTIONARIES);

            return [
                'imported' => false,
                'categories' => KastaCategory::query()->count(),
                'attributes' => KastaAttribute::query()->count(),
                'attribute_values' => KastaAttributeValue::query()->count(),
            ];
        }

        $summary = $this->importDictionariesAction->handle(null, $user->id);
        $this->stateService->markStepCompleted($user, ShopOnboardingService::STEP_DICTIONARIES, [
            'dictionary_summary' => $summary,
        ]);

        return array_merge(['imported' => true], $summary);
    }

    public function ensureDefaultFeedProfile(User $user): FeedProfile
    {
        $user = $user->fresh() ?? $user;
        $shop = $this->requireShop($user);
        $connection = $this->defaultSourceConnection($shop);

        if (! $connection instanceof SourceConnection) {
            throw new RuntimeException('Configure a source connection before creating the default feed profile.');
        }

        $profile = $shop->feedProfiles()
            ->where('source_connection_id', $connection->id)
            ->orderByDesc('id')
            ->first();

        $defaults = $this->defaultFeedProfilePayload($shop, $connection, $user);

        if (! $profile instanceof FeedProfile) {
            $profile = FeedProfile::create($defaults);
        } else {
            $settings = array_merge($defaults['settings'], $profile->settings ?? []);

            $profile->fill([
                'user_id' => $profile->user_id ?: $user->id,
                'status' => $profile->status ?: FeedProfile::STATUS_ACTIVE,
                'currency' => $profile->currency ?: $shop->currency,
                'language' => $profile->language ?: $shop->locale,
                'build_interval_minutes' => $profile->build_interval_minutes ?: 60,
                'settings' => $settings,
            ]);

            if (blank($profile->name)) {
                $profile->name = $defaults['name'];
            }

            if (blank($profile->code)) {
                $profile->code = $defaults['code'];
            }

            if ($profile->next_build_at === null && $profile->status === FeedProfile::STATUS_ACTIVE) {
                $profile->next_build_at = now()->addMinutes($profile->build_interval_minutes);
            }

            $profile->save();
        }

        $this->stateService->markStepCompleted($user, ShopOnboardingService::STEP_FEED_PROFILE, [
            'feed_profile_id' => $profile->id,
        ]);

        return $profile->refresh();
    }

    public function runFirstSync(User $user): SourceImport
    {
        $user = $user->fresh() ?? $user;
        $connection = $this->requireSourceConnection($user);
        $import = $this->sourceSyncWorkflow->run($connection);

        if ($import->status === SourceImport::STATUS_NORMALIZED) {
            $this->stateService->markStepCompleted($user, ShopOnboardingService::STEP_FIRST_SYNC, [
                'source_import_id' => $import->id,
            ]);
        }

        return $import;
    }

    /**
     * @return array<string, int>
     */
    public function applyInitialMappings(User $user, ?FeedProfile $feedProfile = null): array
    {
        $user = $user->fresh() ?? $user;
        $feedProfile ??= $this->ensureDefaultFeedProfile($user);

        $categorySummary = $this->runCategoryAutomapAction->handle($feedProfile);
        $attributeSummary = ['created' => 0, 'skipped' => 0];

        $sourceCategoryIds = $feedProfile->categoryMappings()
            ->where('is_active', true)
            ->pluck('source_category_id')
            ->unique()
            ->values()
            ->all();

        foreach ($sourceCategoryIds as $sourceCategoryId) {
            $summary = $this->applyAttributeSuggestionsAction->handle($feedProfile, (int) $sourceCategoryId);
            $attributeSummary['created'] += (int) ($summary['created'] ?? 0);
            $attributeSummary['skipped'] += (int) ($summary['skipped'] ?? 0);
        }

        $valueSummary = ['created' => 0];

        AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->orderBy('id')
            ->get()
            ->each(function (AttributeMapping $attributeMapping) use (&$valueSummary): void {
                $summary = $this->approveValueSuggestionsAction->handle($attributeMapping);
                $valueSummary['created'] += (int) ($summary['created'] ?? 0);
            });

        $summary = [
            'category_mappings_created' => (int) ($categorySummary['created'] ?? 0),
            'category_mappings_updated' => (int) ($categorySummary['updated'] ?? 0),
            'attribute_mappings_created' => $attributeSummary['created'],
            'value_mappings_created' => $valueSummary['created'],
        ];

        $this->stateService->markStepCompleted($user, ShopOnboardingService::STEP_MAPPING_BOOTSTRAP, [
            'mapping_bootstrap_summary' => $summary,
        ]);

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function applyValueSuggestions(User $user, ?FeedProfile $feedProfile = null): array
    {
        $user = $user->fresh() ?? $user;
        $feedProfile ??= $this->ensureDefaultFeedProfile($user);
        $valueSummary = ['created' => 0];

        AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->orderBy('id')
            ->get()
            ->each(function (AttributeMapping $attributeMapping) use (&$valueSummary): void {
                $summary = $this->approveValueSuggestionsAction->handle($attributeMapping);
                $valueSummary['created'] += (int) ($summary['created'] ?? 0);
            });

        $this->stateService->markStepCompleted($user, ShopOnboardingService::STEP_MAPPING_BOOTSTRAP, [
            'value_mapping_summary' => $valueSummary,
        ]);

        return $valueSummary;
    }

    public function buildReleaseCandidate(User $user, ?FeedProfile $feedProfile = null): FeedGeneration
    {
        $user = $user->fresh() ?? $user;
        $feedProfile ??= $this->ensureDefaultFeedProfile($user);
        $generation = $this->feedBuildService->build($feedProfile);

        if ($generation->release_status === FeedGeneration::RELEASE_STATUS_BUILT) {
            $generation = $this->feedReleaseService->markCandidate(
                $generation,
                $user,
                'Initial onboarding release candidate'
            );
        }

        $this->stateService->markStepCompleted($user, ShopOnboardingService::STEP_BUILD_CANDIDATE, [
            'generation_id' => $generation->id,
        ]);

        return $generation->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFeedProfilePayload(Shop $shop, SourceConnection $connection, User $user): array
    {
        return [
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'user_id' => $user->id,
            'name' => 'Kasta Main',
            'code' => $this->uniqueFeedProfileCode($shop),
            'status' => FeedProfile::STATUS_ACTIVE,
            'currency' => $shop->currency ?: 'UAH',
            'language' => $shop->locale ?: 'uk',
            'include_unavailable' => false,
            'auto_sync' => true,
            'auto_build' => false,
            'build_interval_minutes' => 60,
            'settings' => [
                'publish_guard_enabled' => true,
                'minimum_ready_items' => 1,
                'maximum_invalid_ratio' => 0.25,
                'block_publish_on_critical_conformance' => true,
                'minimum_pictures' => 1,
            ],
            'next_build_at' => now()->addHour(),
        ];
    }

    private function uniqueFeedProfileCode(Shop $shop): string
    {
        $base = sprintf('%s-kasta-main', $shop->slug ?: 'shop');
        $code = $base;
        $suffix = 1;

        while (FeedProfile::query()->where('shop_id', $shop->id)->where('code', $code)->exists()) {
            $suffix++;
            $code = $base.'-'.$suffix;
        }

        return $code;
    }

    private function dictionariesImported(): bool
    {
        return KastaCategory::query()->exists()
            && KastaAttribute::query()->exists()
            && KastaAttributeValue::query()->exists();
    }

    private function requireShop(User $user): Shop
    {
        $user->loadMissing('shop');

        if (! $user->shop instanceof Shop) {
            throw new RuntimeException('Create the shop first before running bootstrap actions.');
        }

        return $user->shop;
    }

    private function requireSourceConnection(User $user): SourceConnection
    {
        $connection = $this->defaultSourceConnection($this->requireShop($user));

        if (! $connection instanceof SourceConnection) {
            throw new RuntimeException('Configure and save a source connection before syncing.');
        }

        return $connection;
    }

    private function defaultSourceConnection(Shop $shop): ?SourceConnection
    {
        return $shop->sourceConnections()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first();
    }
}
