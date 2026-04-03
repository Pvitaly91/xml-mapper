<?php

namespace App\Services\Promotion;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\PromotionRun;
use App\Models\PromotionSnapshot;
use App\Models\SourceAttributeValue;
use App\Models\SourceConnection;
use App\Models\User;
use App\Models\ValueMapping;
use App\Services\Feeds\FeedReleaseAuditService;
use App\Services\Ops\EnvironmentContextService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class PromotionService
{
    public function __construct(
        private readonly PromotionSnapshotService $snapshotService,
        private readonly PromotionPlannerService $plannerService,
        private readonly PromotionReportService $reportService,
        private readonly EnvironmentContextService $environmentContextService,
        private readonly FeedReleaseAuditService $auditService,
    ) {}

    public function generateSnapshot(
        FeedProfile $feedProfile,
        ?User $user = null,
        ?string $environmentClass = null,
        ?string $environmentLabel = null,
        ?string $name = null
    ): PromotionSnapshot {
        $environment = $this->environmentSummary($environmentClass, $environmentLabel);
        $snapshot = $this->snapshotService->generate(
            $feedProfile,
            $environment['class'],
            $environment['label'],
            $user,
            PromotionSnapshot::SOURCE_GENERATED,
            $name
        );

        $this->auditService->record(
            $feedProfile,
            $feedProfile->latestGeneration,
            'promotion_snapshot_generated',
            $user,
            'Promotion snapshot generated.',
            [
                'promotion_snapshot_id' => $snapshot->id,
                'checksum' => $snapshot->checksum,
                'environment' => $environment,
            ]
        );

        return $snapshot;
    }

    public function importSnapshotForTarget(
        FeedProfile $targetFeedProfile,
        string $json,
        ?User $user = null,
        ?string $name = null
    ): PromotionSnapshot {
        $snapshot = $this->snapshotService->importFromJson($json, $user, $name);

        $snapshot->forceFill([
            'shop_id' => $targetFeedProfile->shop_id,
            'user_id' => $user?->id,
        ])->save();

        $this->auditService->record(
            $targetFeedProfile,
            $targetFeedProfile->latestGeneration,
            'promotion_snapshot_imported',
            $user,
            'Promotion snapshot imported for target review.',
            [
                'promotion_snapshot_id' => $snapshot->id,
                'checksum' => $snapshot->checksum,
                'source_environment' => $snapshot->environment_class,
            ]
        );

        return $snapshot->refresh();
    }

    public function compareBetweenProfiles(
        FeedProfile $sourceFeedProfile,
        FeedProfile $targetFeedProfile,
        ?User $user = null,
        ?string $sourceEnvironmentClass = null,
        ?string $sourceEnvironmentLabel = null,
        ?string $reason = null
    ): PromotionRun {
        $sourceSnapshot = $this->generateSnapshot(
            $sourceFeedProfile,
            $user,
            $sourceEnvironmentClass,
            $sourceEnvironmentLabel,
            $sourceFeedProfile->code.' compare snapshot'
        );

        return $this->compareSnapshot($sourceSnapshot, $targetFeedProfile, $user, $reason);
    }

    public function dryRunBetweenProfiles(
        FeedProfile $sourceFeedProfile,
        FeedProfile $targetFeedProfile,
        string $strategy = PromotionRun::STRATEGY_SAFE_MERGE,
        ?User $user = null,
        ?string $sourceEnvironmentClass = null,
        ?string $sourceEnvironmentLabel = null,
        ?string $reason = null
    ): PromotionRun {
        $sourceSnapshot = $this->generateSnapshot(
            $sourceFeedProfile,
            $user,
            $sourceEnvironmentClass,
            $sourceEnvironmentLabel,
            $sourceFeedProfile->code.' dry-run snapshot'
        );

        return $this->dryRunSnapshot($sourceSnapshot, $targetFeedProfile, $strategy, $user, $reason);
    }

    public function applyBetweenProfiles(
        FeedProfile $sourceFeedProfile,
        FeedProfile $targetFeedProfile,
        string $strategy = PromotionRun::STRATEGY_SAFE_MERGE,
        ?User $user = null,
        ?string $sourceEnvironmentClass = null,
        ?string $sourceEnvironmentLabel = null,
        ?string $reason = null
    ): PromotionRun {
        $sourceSnapshot = $this->generateSnapshot(
            $sourceFeedProfile,
            $user,
            $sourceEnvironmentClass,
            $sourceEnvironmentLabel,
            $sourceFeedProfile->code.' apply snapshot'
        );

        return $this->applySnapshot($sourceSnapshot, $targetFeedProfile, $strategy, $user, $reason);
    }

    public function compareSnapshot(
        PromotionSnapshot $sourceSnapshot,
        FeedProfile $targetFeedProfile,
        ?User $user = null,
        ?string $reason = null
    ): PromotionRun {
        $targetSnapshot = $this->currentTargetSnapshot($targetFeedProfile, $user);
        $run = $this->startRun(PromotionRun::MODE_COMPARE, $sourceSnapshot, $targetFeedProfile, null, $user, $reason, $targetSnapshot);
        $drift = $this->plannerService->driftReport($sourceSnapshot, $targetFeedProfile->fresh());
        $status = match ($drift['status']) {
            'incompatible' => PromotionRun::STATUS_BLOCKED,
            'drift_detected' => PromotionRun::STATUS_WARNING,
            default => PromotionRun::STATUS_SUCCEEDED,
        };

        return $this->finishRun(
            $run,
            $status,
            [
                'drift' => $drift,
                'source_snapshot_checksum' => $sourceSnapshot->checksum,
                'target_snapshot_checksum' => $targetSnapshot->checksum,
            ],
            (array) ($drift['warnings'] ?? []),
            (array) ($drift['errors'] ?? [])
        );
    }

    public function dryRunSnapshot(
        PromotionSnapshot $sourceSnapshot,
        FeedProfile $targetFeedProfile,
        string $strategy = PromotionRun::STRATEGY_SAFE_MERGE,
        ?User $user = null,
        ?string $reason = null
    ): PromotionRun {
        $targetSnapshot = $this->currentTargetSnapshot($targetFeedProfile, $user);
        $run = $this->startRun(PromotionRun::MODE_DRY_RUN, $sourceSnapshot, $targetFeedProfile, $strategy, $user, $reason, $targetSnapshot);
        $plan = $this->plannerService->plan($sourceSnapshot, $targetFeedProfile->fresh(), $strategy);
        $status = $this->statusForPlan($plan);

        return $this->finishRun(
            $run,
            $status,
            [
                'plan' => $plan,
                'drift' => $plan['drift'],
                'source_snapshot_checksum' => $sourceSnapshot->checksum,
                'target_snapshot_checksum' => $targetSnapshot->checksum,
                'secret_rebind' => $plan['secret_rebind'],
                'rollback_supported' => false,
            ],
            $plan['warnings'],
            $plan['errors']
        );
    }

    public function applySnapshot(
        PromotionSnapshot $sourceSnapshot,
        FeedProfile $targetFeedProfile,
        string $strategy = PromotionRun::STRATEGY_SAFE_MERGE,
        ?User $user = null,
        ?string $reason = null
    ): PromotionRun {
        $targetSnapshot = $this->currentTargetSnapshot($targetFeedProfile, $user);
        $run = $this->startRun(PromotionRun::MODE_APPLY, $sourceSnapshot, $targetFeedProfile, $strategy, $user, $reason, $targetSnapshot);
        $plan = $this->plannerService->plan($sourceSnapshot, $targetFeedProfile->fresh(), $strategy);

        if ($this->planHasBlockingIssues($plan)) {
            return $this->finishRun(
                $run,
                PromotionRun::STATUS_BLOCKED,
                [
                    'plan' => $plan,
                    'drift' => $plan['drift'],
                    'source_snapshot_checksum' => $sourceSnapshot->checksum,
                    'target_snapshot_checksum' => $targetSnapshot->checksum,
                    'secret_rebind' => $plan['secret_rebind'],
                    'rollback_supported' => false,
                ],
                $plan['warnings'],
                $plan['errors']
            );
        }

        try {
            DB::transaction(function () use ($sourceSnapshot, $targetFeedProfile, $plan): void {
                $this->applyPlan($sourceSnapshot, $targetFeedProfile->fresh(['shop', 'sourceConnection']), $plan);
            });
        } catch (Throwable $exception) {
            return $this->finishRun(
                $run,
                PromotionRun::STATUS_FAILED,
                [
                    'plan' => $plan,
                    'drift' => $plan['drift'],
                    'source_snapshot_checksum' => $sourceSnapshot->checksum,
                    'target_snapshot_checksum' => $targetSnapshot->checksum,
                    'secret_rebind' => $plan['secret_rebind'],
                    'rollback_supported' => false,
                ],
                $plan['warnings'],
                array_values(array_unique(array_merge($plan['errors'], [$exception->getMessage()])))
            );
        }

        $resultSnapshot = $this->currentTargetSnapshot($targetFeedProfile->fresh(), $user, $targetFeedProfile->code.' post-apply snapshot');
        $status = ($plan['secret_rebind']['required'] ?? false) === true
            ? PromotionRun::STATUS_WARNING
            : PromotionRun::STATUS_SUCCEEDED;

        return $this->finishRun(
            $run,
            $status,
            [
                'plan' => $plan,
                'drift' => $plan['drift'],
                'source_snapshot_checksum' => $sourceSnapshot->checksum,
                'target_snapshot_checksum' => $targetSnapshot->checksum,
                'result_snapshot_checksum' => $resultSnapshot->checksum,
                'secret_rebind' => $plan['secret_rebind'],
                'rollback_supported' => true,
            ],
            $plan['warnings'],
            $plan['errors'],
            $resultSnapshot
        );
    }

    public function rollback(
        PromotionRun $promotionRun,
        ?User $user = null,
        ?string $reason = null
    ): PromotionRun {
        if (! $promotionRun->canRollback() || ! $promotionRun->targetFeedProfile || ! $promotionRun->targetSnapshot) {
            $run = $this->startRun(
                PromotionRun::MODE_ROLLBACK,
                $promotionRun->targetSnapshot ?: $promotionRun->sourceSnapshot,
                $promotionRun->targetFeedProfile,
                PromotionRun::STRATEGY_OVERWRITE_TARGET,
                $user,
                $reason,
                null,
                $promotionRun
            );

            return $this->finishRun(
                $run,
                PromotionRun::STATUS_BLOCKED,
                [
                    'rollback_of_promotion_run_id' => $promotionRun->id,
                    'rollback_supported' => false,
                ],
                [],
                ['This promotion run cannot be rolled back safely.']
            );
        }

        $targetFeedProfile = $promotionRun->targetFeedProfile->fresh();
        $currentSnapshot = $this->currentTargetSnapshot($targetFeedProfile, $user, $targetFeedProfile->code.' pre-rollback snapshot');
        $run = $this->startRun(
            PromotionRun::MODE_ROLLBACK,
            $promotionRun->targetSnapshot,
            $targetFeedProfile,
            PromotionRun::STRATEGY_OVERWRITE_TARGET,
            $user,
            $reason,
            $currentSnapshot,
            $promotionRun
        );

        if ($promotionRun->resultSnapshot && $currentSnapshot->checksum !== $promotionRun->resultSnapshot->checksum) {
            return $this->finishRun(
                $run,
                PromotionRun::STATUS_BLOCKED,
                [
                    'rollback_of_promotion_run_id' => $promotionRun->id,
                    'current_checksum' => $currentSnapshot->checksum,
                    'expected_checksum' => $promotionRun->resultSnapshot->checksum,
                    'rollback_supported' => false,
                ],
                [],
                ['Rollback is risky because target config changed after the original promotion apply run.']
            );
        }

        $plan = $this->plannerService->plan($promotionRun->targetSnapshot, $targetFeedProfile, PromotionRun::STRATEGY_OVERWRITE_TARGET);

        if ($this->planHasBlockingIssues($plan)) {
            return $this->finishRun(
                $run,
                PromotionRun::STATUS_BLOCKED,
                [
                    'plan' => $plan,
                    'rollback_of_promotion_run_id' => $promotionRun->id,
                    'rollback_supported' => false,
                ],
                $plan['warnings'],
                $plan['errors']
            );
        }

        try {
            DB::transaction(function () use ($promotionRun, $targetFeedProfile, $plan): void {
                $this->applyPlan($promotionRun->targetSnapshot, $targetFeedProfile->fresh(['shop', 'sourceConnection']), $plan);
            });
        } catch (Throwable $exception) {
            return $this->finishRun(
                $run,
                PromotionRun::STATUS_FAILED,
                [
                    'plan' => $plan,
                    'rollback_of_promotion_run_id' => $promotionRun->id,
                    'rollback_supported' => false,
                ],
                $plan['warnings'],
                array_values(array_unique(array_merge($plan['errors'], [$exception->getMessage()])))
            );
        }

        $resultSnapshot = $this->currentTargetSnapshot($targetFeedProfile->fresh(), $user, $targetFeedProfile->code.' post-rollback snapshot');

        return $this->finishRun(
            $run,
            PromotionRun::STATUS_SUCCEEDED,
            [
                'plan' => $plan,
                'rollback_of_promotion_run_id' => $promotionRun->id,
                'result_snapshot_checksum' => $resultSnapshot->checksum,
                'rollback_supported' => true,
                'secret_rebind' => $plan['secret_rebind'],
            ],
            $plan['warnings'],
            $plan['errors'],
            $resultSnapshot
        );
    }

    private function applyPlan(PromotionSnapshot $sourceSnapshot, FeedProfile $targetFeedProfile, array $plan): void
    {
        $sourcePayload = (array) ($sourceSnapshot->payload ?? []);
        $targetFeedProfile->loadMissing(['shop', 'sourceConnection']);

        $this->applyShopOperation((array) data_get($plan, 'operations.shop.0', []), $targetFeedProfile);
        $this->applyOnboardingOperation((array) data_get($plan, 'operations.onboarding.0', []), $targetFeedProfile);
        $this->applySourceConnectionOperation((array) data_get($plan, 'operations.source_connection.0', []), $sourcePayload, $sourceSnapshot, $targetFeedProfile);
        $targetFeedProfile->refresh();
        $this->applyFeedProfileOperation((array) data_get($plan, 'operations.feed_profile.0', []), $targetFeedProfile);

        $categoryOperations = collect((array) data_get($plan, 'operations.category_mappings', []));
        $attributeOperations = collect((array) data_get($plan, 'operations.attribute_mappings', []));
        $valueOperations = collect((array) data_get($plan, 'operations.value_mappings', []));

        foreach ($categoryOperations->whereIn('action', ['create', 'update'])->all() as $operation) {
            $this->applyCategoryMappingOperation((array) $operation, $targetFeedProfile);
        }

        foreach ($attributeOperations->whereIn('action', ['create', 'update'])->all() as $operation) {
            $this->applyAttributeMappingOperation((array) $operation, $targetFeedProfile);
        }

        foreach ($valueOperations->whereIn('action', ['create', 'update'])->all() as $operation) {
            $this->applyValueMappingOperation((array) $operation, $targetFeedProfile);
        }

        foreach ($valueOperations->where('action', 'delete')->all() as $operation) {
            $this->applyValueMappingOperation((array) $operation, $targetFeedProfile);
        }

        foreach ($attributeOperations->where('action', 'delete')->all() as $operation) {
            $this->applyAttributeMappingOperation((array) $operation, $targetFeedProfile);
        }

        foreach ($categoryOperations->where('action', 'delete')->all() as $operation) {
            $this->applyCategoryMappingOperation((array) $operation, $targetFeedProfile);
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyShopOperation(array $operation, FeedProfile $targetFeedProfile): void
    {
        if (($operation['action'] ?? 'skip') === 'skip') {
            return;
        }

        $shop = $targetFeedProfile->shop()->firstOrFail();
        $incoming = (array) ($operation['incoming'] ?? []);
        $shop->forceFill([
            'currency' => $incoming['currency'] ?? $shop->currency,
            'locale' => $incoming['locale'] ?? $shop->locale,
            'timezone' => $incoming['timezone'] ?? $shop->timezone,
            'is_active' => (bool) ($incoming['is_active'] ?? $shop->is_active),
            'settings' => array_merge((array) ($shop->settings ?? []), (array) ($incoming['settings'] ?? [])),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyOnboardingOperation(array $operation, FeedProfile $targetFeedProfile): void
    {
        if (($operation['action'] ?? 'skip') === 'skip') {
            return;
        }

        $shop = $targetFeedProfile->shop()->firstOrFail();
        $settings = (array) ($shop->settings ?? []);
        $settings['onboarding'] = array_merge((array) ($settings['onboarding'] ?? []), (array) ($operation['incoming'] ?? []));
        $shop->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyFeedProfileOperation(array $operation, FeedProfile $targetFeedProfile): void
    {
        if (($operation['action'] ?? 'skip') === 'skip') {
            return;
        }

        $incoming = (array) ($operation['incoming'] ?? []);
        $targetFeedProfile->forceFill([
            'status' => $incoming['status'] ?? $targetFeedProfile->status,
            'currency' => $incoming['currency'] ?? $targetFeedProfile->currency,
            'language' => $incoming['language'] ?? $targetFeedProfile->language,
            'include_unavailable' => (bool) ($incoming['include_unavailable'] ?? $targetFeedProfile->include_unavailable),
            'auto_sync' => (bool) ($incoming['auto_sync'] ?? $targetFeedProfile->auto_sync),
            'auto_build' => (bool) ($incoming['auto_build'] ?? $targetFeedProfile->auto_build),
            'build_interval_minutes' => (int) ($incoming['build_interval_minutes'] ?? $targetFeedProfile->build_interval_minutes),
            'settings' => array_merge((array) ($targetFeedProfile->settings ?? []), (array) ($incoming['settings'] ?? [])),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $sourcePayload
     */
    private function applySourceConnectionOperation(
        array $operation,
        array $sourcePayload,
        PromotionSnapshot $sourceSnapshot,
        FeedProfile $targetFeedProfile
    ): void {
        if (($operation['action'] ?? 'skip') === 'skip') {
            return;
        }

        $connection = $targetFeedProfile->sourceConnection;
        $metadata = (array) data_get($operation, 'incoming', []);
        $secretPolicy = (array) data_get($operation, 'secret_policy', data_get($sourcePayload, 'source_connection.secret_policy', []));

        if (! $connection instanceof SourceConnection) {
            $connection = new SourceConnection([
                'shop_id' => $targetFeedProfile->shop_id,
                'name' => (string) data_get($sourcePayload, 'source_connection.identity.name', $targetFeedProfile->name.' Source'),
                'code' => (string) data_get($sourcePayload, 'source_connection.identity.code', $targetFeedProfile->code.'-source'),
                'driver' => (string) data_get($sourcePayload, 'source_connection.driver'),
            ]);
        }

        $options = array_merge(
            (array) Arr::except((array) ($connection->options ?? []), ['promotion_meta']),
            (array) ($metadata['options'] ?? [])
        );

        $connection->fill([
            'status' => $metadata['status'] ?? $connection->status ?? SourceConnection::STATUS_ACTIVE,
            'source_url' => $metadata['source_url'] ?? $connection->source_url,
            'api_base_url' => $metadata['api_base_url'] ?? $connection->api_base_url,
            'api_version' => $metadata['api_version'] ?? $connection->api_version,
            'sync_interval_minutes' => (int) ($metadata['sync_interval_minutes'] ?? $connection->sync_interval_minutes ?? 60),
            'options' => $options,
        ]);

        if ($connection->status === SourceConnection::STATUS_ACTIVE && $connection->next_sync_at === null) {
            $connection->next_sync_at = now()->addMinutes($connection->sync_interval_minutes ?: 60);
        }

        $connection->applyPromotionSecretPolicy($secretPolicy, $sourceSnapshot->checksum)->save();

        if ($targetFeedProfile->source_connection_id !== $connection->id) {
            $targetFeedProfile->forceFill(['source_connection_id' => $connection->id])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyCategoryMappingOperation(array $operation, FeedProfile $targetFeedProfile): void
    {
        if (($operation['action'] ?? 'skip') === 'delete') {
            $current = (array) ($operation['current'] ?? []);
            $sourceCategory = $this->plannerService->resolveSourceCategory($targetFeedProfile, (array) data_get($current, 'identity.source_category', []));

            if ($sourceCategory) {
                CategoryMapping::query()
                    ->where('feed_profile_id', $targetFeedProfile->id)
                    ->where('source_category_id', $sourceCategory->id)
                    ->delete();
            }

            return;
        }

        if (! in_array($operation['action'] ?? 'skip', ['create', 'update'], true)) {
            return;
        }

        $incoming = (array) ($operation['incoming'] ?? []);
        $sourceCategory = $this->plannerService->resolveSourceCategory($targetFeedProfile, (array) data_get($incoming, 'identity.source_category', []));
        $kastaCategory = $this->plannerService->resolveKastaCategory((array) data_get($incoming, 'target.kasta_category', []));

        if (! $sourceCategory || ! $kastaCategory) {
            return;
        }

        $mapping = CategoryMapping::query()->firstOrNew([
            'feed_profile_id' => $targetFeedProfile->id,
            'source_category_id' => $sourceCategory->id,
        ]);

        $mapping->fill([
            'shop_id' => $targetFeedProfile->shop_id,
            'source_connection_id' => $targetFeedProfile->source_connection_id,
            'kasta_category_id' => $kastaCategory->id,
            'mapping_strategy' => $incoming['mapping_strategy'] ?? $mapping->mapping_strategy,
            'is_active' => (bool) ($incoming['is_active'] ?? true),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyAttributeMappingOperation(array $operation, FeedProfile $targetFeedProfile): void
    {
        if (($operation['action'] ?? 'skip') === 'delete') {
            $current = (array) ($operation['current'] ?? []);
            $sourceCategory = $this->plannerService->resolveSourceCategory($targetFeedProfile, (array) data_get($current, 'identity.source_category', []));
            $sourceAttribute = $this->plannerService->resolveSourceAttribute($targetFeedProfile, (array) data_get($current, 'identity.source_attribute', []));

            if ($sourceCategory && $sourceAttribute) {
                AttributeMapping::query()
                    ->where('feed_profile_id', $targetFeedProfile->id)
                    ->where('source_category_id', $sourceCategory->id)
                    ->where('source_attribute_id', $sourceAttribute->id)
                    ->delete();
            }

            return;
        }

        if (! in_array($operation['action'] ?? 'skip', ['create', 'update'], true)) {
            return;
        }

        $incoming = (array) ($operation['incoming'] ?? []);
        $sourceCategory = $this->plannerService->resolveSourceCategory($targetFeedProfile, (array) data_get($incoming, 'identity.source_category', []));
        $sourceAttribute = $this->plannerService->resolveSourceAttribute($targetFeedProfile, (array) data_get($incoming, 'identity.source_attribute', []));
        $kastaCategory = $this->plannerService->resolveKastaCategory((array) data_get($incoming, 'target.kasta_category', []));
        $kastaAttribute = $this->plannerService->resolveKastaAttribute($kastaCategory?->id, (array) data_get($incoming, 'target.kasta_attribute', []));

        if (! $sourceCategory || ! $sourceAttribute || ! $kastaCategory || ! $kastaAttribute) {
            return;
        }

        $mapping = AttributeMapping::query()->firstOrNew([
            'feed_profile_id' => $targetFeedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'source_attribute_id' => $sourceAttribute->id,
        ]);

        $mapping->fill([
            'shop_id' => $targetFeedProfile->shop_id,
            'source_connection_id' => $targetFeedProfile->source_connection_id,
            'kasta_category_id' => $kastaCategory->id,
            'kasta_attribute_id' => $kastaAttribute->id,
            'mapping_strategy' => $incoming['mapping_strategy'] ?? $mapping->mapping_strategy,
            'is_required' => (bool) ($incoming['is_required'] ?? false),
            'default_value' => $incoming['default_value'] ?? null,
            'use_variant_value' => (bool) ($incoming['use_variant_value'] ?? false),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyValueMappingOperation(array $operation, FeedProfile $targetFeedProfile): void
    {
        if (($operation['action'] ?? 'skip') === 'delete') {
            $current = (array) ($operation['current'] ?? []);
            $sourceCategory = $this->plannerService->resolveSourceCategory($targetFeedProfile, (array) data_get($current, 'identity.source_category', []));
            $sourceAttribute = $this->plannerService->resolveSourceAttribute($targetFeedProfile, (array) data_get($current, 'identity.source_attribute', []));

            if ($sourceCategory && $sourceAttribute) {
                $attributeMapping = AttributeMapping::query()
                    ->where('feed_profile_id', $targetFeedProfile->id)
                    ->where('source_category_id', $sourceCategory->id)
                    ->where('source_attribute_id', $sourceAttribute->id)
                    ->first();

                if ($attributeMapping) {
                    ValueMapping::query()
                        ->where('attribute_mapping_id', $attributeMapping->id)
                        ->where('source_raw_value', (string) data_get($current, 'identity.source_value.raw_value'))
                        ->delete();
                }
            }

            return;
        }

        if (! in_array($operation['action'] ?? 'skip', ['create', 'update'], true)) {
            return;
        }

        $incoming = (array) ($operation['incoming'] ?? []);
        $sourceCategory = $this->plannerService->resolveSourceCategory($targetFeedProfile, (array) data_get($incoming, 'identity.source_category', []));
        $sourceAttribute = $this->plannerService->resolveSourceAttribute($targetFeedProfile, (array) data_get($incoming, 'identity.source_attribute', []));

        if (! $sourceCategory || ! $sourceAttribute) {
            return;
        }

        $attributeMapping = AttributeMapping::query()
            ->where('feed_profile_id', $targetFeedProfile->id)
            ->where('source_category_id', $sourceCategory->id)
            ->where('source_attribute_id', $sourceAttribute->id)
            ->first();

        if (! $attributeMapping instanceof AttributeMapping) {
            return;
        }

        $rawValue = (string) data_get($incoming, 'identity.source_value.raw_value');
        $normalizedValue = (string) data_get($incoming, 'identity.source_value.normalized_value');
        $sourceAttributeValue = SourceAttributeValue::query()
            ->where('source_attribute_id', $sourceAttribute->id)
            ->where(function ($query) use ($rawValue, $normalizedValue): void {
                if ($rawValue !== '') {
                    $query->orWhere('raw_value', $rawValue);
                }

                if ($normalizedValue !== '') {
                    $query->orWhere('normalized_value', $normalizedValue);
                }
            })
            ->first();
        $kastaAttributeValue = $this->plannerService->resolveKastaAttributeValue(
            $attributeMapping->kasta_attribute_id,
            (array) data_get($incoming, 'target.kasta_attribute_value', [])
        );

        $mapping = ValueMapping::query()->firstOrNew([
            'attribute_mapping_id' => $attributeMapping->id,
            'source_raw_value' => $rawValue,
        ]);

        $mapping->fill([
            'shop_id' => $targetFeedProfile->shop_id,
            'feed_profile_id' => $targetFeedProfile->id,
            'source_attribute_value_id' => $sourceAttributeValue?->id,
            'kasta_attribute_value_id' => $kastaAttributeValue?->id,
            'normalized_source_value' => $normalizedValue,
            'target_value' => $kastaAttributeValue?->value ?: (string) data_get($incoming, 'target.kasta_attribute_value.value'),
            'mapping_strategy' => $incoming['mapping_strategy'] ?? $mapping->mapping_strategy,
            'is_active' => (bool) ($incoming['is_active'] ?? true),
        ])->save();
    }

    private function statusForPlan(array $plan): string
    {
        if ($plan['errors'] !== []) {
            return PromotionRun::STATUS_BLOCKED;
        }

        if (collect($plan['operations'])->flatten(1)->where('action', 'conflict')->isNotEmpty()) {
            return PromotionRun::STATUS_BLOCKED;
        }

        return $plan['conflicts'] !== []
            ? PromotionRun::STATUS_WARNING
            : PromotionRun::STATUS_SUCCEEDED;
    }

    private function planHasBlockingIssues(array $plan): bool
    {
        return $plan['errors'] !== []
            || collect($plan['operations'])->flatten(1)->where('action', 'conflict')->isNotEmpty();
    }

    private function currentTargetSnapshot(
        FeedProfile $targetFeedProfile,
        ?User $user = null,
        ?string $name = null
    ): PromotionSnapshot {
        $environment = $this->environmentSummary();

        return $this->snapshotService->generate(
            $targetFeedProfile,
            $environment['class'],
            $environment['label'],
            $user,
            PromotionSnapshot::SOURCE_GENERATED,
            $name ?: ($targetFeedProfile->code.' target snapshot')
        );
    }

    /**
     * @return array{class:string,label:string}
     */
    private function environmentSummary(?string $environmentClass = null, ?string $environmentLabel = null): array
    {
        $environment = $this->environmentContextService->summary();

        return [
            'class' => $environmentClass ?: $environment['class'],
            'label' => $environmentLabel ?: $environment['label'],
        ];
    }

    private function startRun(
        string $mode,
        ?PromotionSnapshot $sourceSnapshot,
        ?FeedProfile $targetFeedProfile,
        ?string $strategy = null,
        ?User $user = null,
        ?string $reason = null,
        ?PromotionSnapshot $targetSnapshot = null,
        ?PromotionRun $rollbackOf = null
    ): PromotionRun {
        $environment = $this->environmentSummary();

        return PromotionRun::create([
            'shop_id' => $targetFeedProfile?->shop_id ?: $sourceSnapshot?->shop_id,
            'source_feed_profile_id' => $sourceSnapshot?->feed_profile_id,
            'target_feed_profile_id' => $targetFeedProfile?->id,
            'source_snapshot_id' => $sourceSnapshot?->id,
            'target_snapshot_id' => $targetSnapshot?->id,
            'rollback_of_promotion_run_id' => $rollbackOf?->id,
            'user_id' => $user?->id,
            'source_environment' => $sourceSnapshot?->environment_class,
            'target_environment' => $environment['class'],
            'mode' => $mode,
            'strategy' => $strategy,
            'status' => PromotionRun::STATUS_RUNNING,
            'reason' => $reason,
            'started_at' => now(),
        ]);
    }

    private function finishRun(
        PromotionRun $run,
        string $status,
        array $summary,
        array $warnings = [],
        array $errors = [],
        ?PromotionSnapshot $resultSnapshot = null
    ): PromotionRun {
        $run->forceFill([
            'status' => $status,
            'summary' => $summary,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
            'result_snapshot_id' => $resultSnapshot?->id,
            'finished_at' => now(),
        ])->save();

        $run = $run->fresh(['sourceSnapshot', 'targetSnapshot', 'resultSnapshot', 'targetFeedProfile', 'user']);
        $report = $this->reportService->store($run);
        $run->forceFill([
            'summary' => array_merge($run->summary ?? [], [
                'report' => Arr::except($report, ['content']),
            ]),
        ])->save();

        $action = match ($run->mode) {
            PromotionRun::MODE_COMPARE => 'promotion_compared',
            PromotionRun::MODE_DRY_RUN => 'promotion_dry_run_completed',
            PromotionRun::MODE_APPLY => $status === PromotionRun::STATUS_SUCCEEDED || $status === PromotionRun::STATUS_WARNING
                ? 'promotion_applied'
                : 'promotion_apply_blocked',
            PromotionRun::MODE_ROLLBACK => $status === PromotionRun::STATUS_SUCCEEDED
                ? 'promotion_rollback_completed'
                : 'promotion_rollback_blocked',
            default => 'promotion_run_completed',
        };

        if ($run->targetFeedProfile) {
            $this->auditService->record(
                $run->targetFeedProfile,
                $run->targetFeedProfile->latestGeneration,
                $action,
                $run->user,
                $run->reason ?: 'Promotion workflow finished.',
                [
                    'promotion_run_id' => $run->id,
                    'mode' => $run->mode,
                    'status' => $run->status,
                    'strategy' => $run->strategy,
                    'source_snapshot_id' => $run->source_snapshot_id,
                    'target_snapshot_id' => $run->target_snapshot_id,
                    'result_snapshot_id' => $run->result_snapshot_id,
                    'warnings' => $run->warnings,
                    'errors' => $run->errors,
                ]
            );
        }

        return $run->fresh(['sourceSnapshot', 'targetSnapshot', 'resultSnapshot', 'targetFeedProfile', 'user']);
    }
}
