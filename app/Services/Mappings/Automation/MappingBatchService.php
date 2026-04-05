<?php

namespace App\Services\Mappings\Automation;

use App\Models\AttributeMapping;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\MappingBatch;
use App\Models\MappingBatchEntry;
use App\Models\User;
use App\Models\ValueMapping;
use App\Services\Governance\GovernanceAuditService;
use App\Services\Ops\CorrelationContext;
use App\Support\Canonicalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MappingBatchService
{
    public function __construct(
        private readonly MappingSuggestionService $suggestionService,
        private readonly GovernanceAuditService $auditService,
        private readonly CorrelationContext $correlationContext,
    ) {}

    /**
     * @param  array<string, mixed>  $scope
     */
    public function planSuggestionBatch(
        FeedProfile $feedProfile,
        string $mappingType,
        float $threshold,
        string $strategy,
        array $scope = [],
        ?User $actor = null,
        bool $dryRun = false,
        ?string $reason = null,
        ?string $note = null
    ): MappingBatch {
        $suggestions = collect($this->suggestionService->suggest($feedProfile, $mappingType, $scope))
            ->filter(fn (array $suggestion) => (float) $suggestion['confidence'] >= $threshold)
            ->values();

        $batch = MappingBatch::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'requested_by_user_id' => $actor?->id,
            'batch_type' => MappingBatch::TYPE_SUGGESTION_APPLY,
            'mapping_type' => $mappingType,
            'status' => $dryRun ? MappingBatch::STATUS_DRY_RUN : MappingBatch::STATUS_PLANNED,
            'strategy' => $strategy,
            'risk_level' => $this->riskLevel($suggestions->all(), $strategy),
            'correlation_id' => $this->correlationContext->ensure(),
            'threshold' => $threshold,
            'scope' => $scope,
            'reason' => $reason,
            'note' => $note,
            'summary' => [
                'planned' => $suggestions->count(),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'conflicted' => 0,
                'warnings' => 0,
            ],
            'warnings' => [],
            'started_at' => now(),
        ]);

        foreach ($suggestions as $suggestion) {
            $plan = $this->planEntry($feedProfile, $mappingType, $suggestion, $strategy);

            $batch->entries()->create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'mapping_type' => $mappingType,
                'source_key' => $plan['source_key'],
                'target_key' => $plan['target_key'],
                'status' => $dryRun ? MappingBatchEntry::STATUS_DRY_RUN : MappingBatchEntry::STATUS_PLANNED,
                'is_manual_conflict' => $plan['is_manual_conflict'],
                'model_type' => $plan['model_type'],
                'model_id' => $plan['model_id'],
                'before_state' => $plan['before_state'],
                'after_state' => $plan['after_state'],
                'suggestion' => $suggestion,
                'warning' => $plan['warning'],
            ]);
        }

        $summary = [
            'warnings' => $batch->entries()->whereNotNull('warning')->count(),
            'conflicted' => $batch->entries()->where('is_manual_conflict', true)->count(),
        ];

        $batch->forceFill([
            'summary' => array_merge((array) $batch->summary, $summary),
            'warnings' => $batch->entries()->whereNotNull('warning')->pluck('warning')->unique()->values()->all(),
        ])->save();

        return $batch->fresh(['entries']);
    }

    public function executeBatch(MappingBatch $batch, User $actor): MappingBatch
    {
        if (! in_array($batch->status, [MappingBatch::STATUS_PLANNED, MappingBatch::STATUS_PENDING_APPROVAL], true)) {
            throw new RuntimeException('Only planned or pending-approval batches can be executed.');
        }

        DB::transaction(function () use ($batch, $actor): void {
            $batch->loadMissing('entries');
            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'conflicted' => 0];

            foreach ($batch->entries as $entry) {
                if ($entry->is_manual_conflict && $batch->strategy !== 'overwrite_existing') {
                    $entry->update(['status' => MappingBatchEntry::STATUS_CONFLICTED]);
                    $summary['conflicted']++;
                    continue;
                }

                $execution = match ($entry->mapping_type) {
                    'category' => $this->executeCategoryEntry($entry),
                    'attribute' => $this->executeAttributeEntry($batch, $entry),
                    'value' => $this->executeValueEntry($entry),
                    default => ['status' => MappingBatchEntry::STATUS_SKIPPED, 'model' => null],
                };

                $entry->update([
                    'status' => $execution['status'],
                    'model_type' => $execution['model']?->getMorphClass(),
                    'model_id' => $execution['model']?->getKey(),
                    'after_state' => $execution['after_state'] ?? $entry->after_state,
                ]);

                match ($execution['status']) {
                    MappingBatchEntry::STATUS_CREATED => $summary['created']++,
                    MappingBatchEntry::STATUS_UPDATED => $summary['updated']++,
                    MappingBatchEntry::STATUS_CONFLICTED => $summary['conflicted']++,
                    default => $summary['skipped']++,
                };
            }

            $batch->forceFill([
                'status' => MappingBatch::STATUS_APPLIED,
                'executed_by_user_id' => $actor->id,
                'finished_at' => now(),
                'summary' => array_merge((array) $batch->summary, $summary),
            ])->save();
        });

        $batch = $batch->fresh(['entries', 'feedProfile.shop']);

        $this->auditService->record(
            'mapping',
            'mapping_batch_executed',
            'Mapping auto-apply batch executed.',
            $actor,
            $batch->feedProfile?->shop,
            $batch,
            severity: 'warning',
            context: [
                'mapping_type' => $batch->mapping_type,
                'strategy' => $batch->strategy,
                'summary' => $batch->summary,
            ],
            targetLabel: 'Batch #'.$batch->id
        );

        return $batch;
    }

    public function rollback(MappingBatch $batch, User $actor, ?string $reason = null): MappingBatch
    {
        if ($batch->status !== MappingBatch::STATUS_APPLIED) {
            throw new RuntimeException('Only applied batches can be rolled back.');
        }

        DB::transaction(function () use ($batch, $actor, $reason): void {
            $batch->loadMissing('entries');

            foreach ($batch->entries->sortByDesc('id') as $entry) {
                $this->rollbackEntry($entry);
            }

            $batch->forceFill([
                'status' => MappingBatch::STATUS_ROLLED_BACK,
                'rolled_back_by_user_id' => $actor->id,
                'rolled_back_at' => now(),
                'reason' => $reason ?: $batch->reason,
            ])->save();
        });

        $batch = $batch->fresh(['entries', 'feedProfile.shop']);

        $this->auditService->record(
            'mapping',
            'mapping_batch_rolled_back',
            'Mapping auto-apply batch rolled back.',
            $actor,
            $batch->feedProfile?->shop,
            $batch,
            severity: 'warning',
            context: [
                'mapping_type' => $batch->mapping_type,
                'reason' => $reason,
            ],
            targetLabel: 'Batch #'.$batch->id
        );

        return $batch;
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     */
    public function riskLevel(array $suggestions, string $strategy): string
    {
        if ($strategy === 'overwrite_existing') {
            return 'high_risk';
        }

        if (count($suggestions) >= (int) config('feed_mediator.mapping_automation.bulk.high_volume_count', 25)) {
            return 'sensitive';
        }

        if (collect($suggestions)->contains(fn (array $suggestion) => ! (bool) ($suggestion['safe_for_auto_apply'] ?? false))) {
            return 'sensitive';
        }

        return 'standard';
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function planEntry(FeedProfile $feedProfile, string $mappingType, array $suggestion, string $strategy): array
    {
        return match ($mappingType) {
            'category' => $this->planCategoryEntry($feedProfile, $suggestion, $strategy),
            'attribute' => $this->planAttributeEntry($feedProfile, $suggestion, $strategy),
            'value' => $this->planValueEntry($feedProfile, $suggestion, $strategy),
            default => throw new RuntimeException('Unsupported mapping type ['.$mappingType.'].'),
        };
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function planCategoryEntry(FeedProfile $feedProfile, array $suggestion, string $strategy): array
    {
        $existing = CategoryMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_category_id', $suggestion['source_category_id'])
            ->first();

        return $this->basePlan(
            'source_category:'.$suggestion['source_category_id'],
            'kasta_category:'.$suggestion['suggested_target']['id'],
            $existing,
            $strategy,
            $existing?->toArray(),
            [
                'shop_id' => $feedProfile->shop_id,
                'source_connection_id' => $feedProfile->source_connection_id,
                'feed_profile_id' => $feedProfile->id,
                'source_category_id' => $suggestion['source_category_id'],
                'kasta_category_id' => $suggestion['suggested_target']['id'],
                'rz_id' => null,
                'mapping_strategy' => 'auto_'.$suggestion['match_strategy'],
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function planAttributeEntry(FeedProfile $feedProfile, array $suggestion, string $strategy): array
    {
        $existing = AttributeMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('source_category_id', $suggestion['source_category_id'])
            ->where('source_attribute_id', $suggestion['source']['id'])
            ->first();

        return $this->basePlan(
            'source_attribute:'.$suggestion['source']['id'],
            'kasta_attribute:'.$suggestion['suggested_target']['id'],
            $existing,
            $strategy,
            $existing?->toArray(),
            [
                'shop_id' => $feedProfile->shop_id,
                'source_connection_id' => $feedProfile->source_connection_id,
                'feed_profile_id' => $feedProfile->id,
                'source_category_id' => $suggestion['source_category_id'],
                'source_attribute_id' => $suggestion['source']['id'],
                'kasta_category_id' => null,
                'kasta_attribute_id' => $suggestion['suggested_target']['id'],
                'mapping_strategy' => 'auto_'.$suggestion['match_strategy'],
                'is_required' => false,
                'default_value' => null,
                'use_variant_value' => false,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function planValueEntry(FeedProfile $feedProfile, array $suggestion, string $strategy): array
    {
        $existing = ValueMapping::query()
            ->where('feed_profile_id', $feedProfile->id)
            ->where('attribute_mapping_id', $suggestion['attribute_mapping_id'])
            ->where(function ($query) use ($suggestion): void {
                $query->where('source_attribute_value_id', $suggestion['source']['id'])
                    ->orWhere('normalized_source_value', Canonicalizer::normalizeKey($suggestion['source']['label']));
            })
            ->first();

        return $this->basePlan(
            'source_value:'.$suggestion['source']['id'],
            'kasta_value:'.$suggestion['suggested_target']['id'],
            $existing,
            $strategy,
            $existing?->toArray(),
            [
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'attribute_mapping_id' => $suggestion['attribute_mapping_id'],
                'source_attribute_value_id' => $suggestion['source']['id'],
                'kasta_attribute_value_id' => $suggestion['suggested_target']['id'],
                'source_raw_value' => $suggestion['source']['label'],
                'normalized_source_value' => Canonicalizer::normalizeText(mb_strtolower((string) $suggestion['source']['label'])),
                'target_value' => $suggestion['suggested_target']['label'],
                'mapping_strategy' => 'auto_'.$suggestion['match_strategy'],
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  array<string, mixed>|null  $beforeState
     * @param  array<string, mixed>  $afterState
     * @return array<string, mixed>
     */
    private function basePlan(
        string $sourceKey,
        string $targetKey,
        mixed $existing,
        string $strategy,
        ?array $beforeState,
        array $afterState
    ): array {
        $isManualConflict = $existing !== null && (($existing->mapping_strategy ?? null) === 'manual');
        $warning = $isManualConflict && $strategy !== 'overwrite_existing'
            ? 'Manual mapping exists and safe strategy will not overwrite it.'
            : null;

        return [
            'source_key' => $sourceKey,
            'target_key' => $targetKey,
            'is_manual_conflict' => $isManualConflict,
            'model_type' => $existing?->getMorphClass(),
            'model_id' => $existing?->getKey(),
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'warning' => $warning,
        ];
    }

    /**
     * @return array{status:string,model:mixed,after_state:?array}
     */
    private function executeCategoryEntry(MappingBatchEntry $entry): array
    {
        $payload = (array) $entry->after_state;
        $mapping = $entry->model_id ? CategoryMapping::query()->find($entry->model_id) : null;

        if ($mapping instanceof CategoryMapping) {
            $mapping->update($payload);

            return ['status' => MappingBatchEntry::STATUS_UPDATED, 'model' => $mapping->fresh(), 'after_state' => $mapping->fresh()?->toArray()];
        }

        $mapping = CategoryMapping::create($payload);

        return ['status' => MappingBatchEntry::STATUS_CREATED, 'model' => $mapping, 'after_state' => $mapping->toArray()];
    }

    /**
     * @return array{status:string,model:mixed,after_state:?array}
     */
    private function executeAttributeEntry(MappingBatch $batch, MappingBatchEntry $entry): array
    {
        $payload = (array) $entry->after_state;

        if (($payload['kasta_category_id'] ?? null) === null) {
            $payload['kasta_category_id'] = CategoryMapping::query()
                ->where('feed_profile_id', $batch->feed_profile_id)
                ->where('source_category_id', $payload['source_category_id'])
                ->value('kasta_category_id');
        }

        $mapping = $entry->model_id ? AttributeMapping::query()->find($entry->model_id) : null;

        if ($mapping instanceof AttributeMapping) {
            $mapping->update($payload);

            return ['status' => MappingBatchEntry::STATUS_UPDATED, 'model' => $mapping->fresh(), 'after_state' => $mapping->fresh()?->toArray()];
        }

        $mapping = AttributeMapping::create($payload);

        return ['status' => MappingBatchEntry::STATUS_CREATED, 'model' => $mapping, 'after_state' => $mapping->toArray()];
    }

    /**
     * @return array{status:string,model:mixed,after_state:?array}
     */
    private function executeValueEntry(MappingBatchEntry $entry): array
    {
        $payload = (array) $entry->after_state;
        $mapping = $entry->model_id ? ValueMapping::query()->find($entry->model_id) : null;

        if ($mapping instanceof ValueMapping) {
            $mapping->update($payload);

            return ['status' => MappingBatchEntry::STATUS_UPDATED, 'model' => $mapping->fresh(), 'after_state' => $mapping->fresh()?->toArray()];
        }

        $mapping = ValueMapping::create($payload);

        return ['status' => MappingBatchEntry::STATUS_CREATED, 'model' => $mapping, 'after_state' => $mapping->toArray()];
    }

    private function rollbackEntry(MappingBatchEntry $entry): void
    {
        $model = match ($entry->mapping_type) {
            'category' => CategoryMapping::query()->find($entry->model_id),
            'attribute' => AttributeMapping::query()->find($entry->model_id),
            'value' => ValueMapping::query()->find($entry->model_id),
            default => null,
        };

        if ($entry->status === MappingBatchEntry::STATUS_CREATED && $model !== null) {
            $model->delete();
            $entry->update(['status' => MappingBatchEntry::STATUS_ROLLED_BACK]);
            return;
        }

        if ($entry->status === MappingBatchEntry::STATUS_UPDATED && $model !== null) {
            $before = (array) ($entry->before_state ?? []);
            unset($before['id'], $before['created_at'], $before['updated_at']);
            $model->update($before);
            $entry->update(['status' => MappingBatchEntry::STATUS_ROLLED_BACK]);
        }
    }
}
