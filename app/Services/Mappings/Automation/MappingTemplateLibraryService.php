<?php

namespace App\Services\Mappings\Automation;

use App\Models\FeedProfile;
use App\Models\MappingRule;
use App\Models\MappingTemplate;
use App\Models\User;
use App\Services\Governance\GovernanceAuditService;
use App\Services\Shops\MappingPresetService;
use App\Support\Canonicalizer;
use Illuminate\Support\Facades\DB;

class MappingTemplateLibraryService
{
    public function __construct(
        private readonly MappingPresetService $presetService,
        private readonly GovernanceAuditService $auditService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function exportPayload(FeedProfile $feedProfile): array
    {
        return array_merge($this->presetService->export($feedProfile), [
            'rules' => MappingRule::query()
                ->where(function ($query) use ($feedProfile): void {
                    $query->where('feed_profile_id', $feedProfile->id)
                        ->orWhere(function ($inner) use ($feedProfile): void {
                            $inner->whereNull('feed_profile_id')
                                ->where('shop_id', $feedProfile->shop_id);
                        });
                })
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (MappingRule $rule) => [
                    'rule_type' => $rule->rule_type,
                    'match_type' => $rule->match_type,
                    'source_pattern' => $rule->source_pattern,
                    'source_normalized' => $rule->source_normalized,
                    'source_attribute_code' => $rule->source_attribute_code,
                    'source_category_path' => $rule->source_category_path,
                    'vendor_scope' => $rule->vendor_scope,
                    'brand_scope' => $rule->brand_scope,
                    'target_reference' => $rule->target_reference,
                    'target_label' => $rule->target_label,
                    'target_payload' => $rule->target_payload,
                    'explanation' => $rule->explanation,
                    'priority' => $rule->priority,
                    'is_auto_apply_safe' => $rule->is_auto_apply_safe,
                ])->values()->all(),
        ]);
    }

    public function storeTemplate(FeedProfile $feedProfile, string $name, string $scope, ?User $actor = null): MappingTemplate
    {
        $payload = $this->exportPayload($feedProfile);
        $template = MappingTemplate::create([
            'shop_id' => $scope === MappingTemplate::SCOPE_GLOBAL ? null : $feedProfile->shop_id,
            'feed_profile_id' => $scope === MappingTemplate::SCOPE_FEED_PROFILE ? $feedProfile->id : null,
            'created_by_user_id' => $actor?->id,
            'name' => $name,
            'scope' => $scope,
            'template_type' => 'mapping_bundle',
            'version' => 1,
            'fingerprint' => Canonicalizer::fingerprint($payload),
            'is_active' => true,
            'payload' => $payload,
            'meta' => [
                'shop_name' => $feedProfile->shop?->name,
                'feed_profile_name' => $feedProfile->name,
            ],
        ]);

        $this->auditService->record(
            'mapping',
            'mapping_template_created',
            'Mapping template exported to the library.',
            $actor,
            $feedProfile->shop,
            $template,
            context: [
                'scope' => $scope,
                'fingerprint' => $template->fingerprint,
            ],
            targetLabel: $name
        );

        return $template;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function previewApply(FeedProfile $feedProfile, array $payload, string $collisionStrategy): array
    {
        return [
            'mapping_plan' => $this->presetService->previewImport($feedProfile, $payload, $collisionStrategy),
            'rule_summary' => $this->previewRules($feedProfile, $payload['rules'] ?? [], $collisionStrategy),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyPayload(FeedProfile $feedProfile, array $payload, string $collisionStrategy, ?User $actor = null): array
    {
        $summary = [];

        DB::transaction(function () use ($feedProfile, $payload, $collisionStrategy, $actor, &$summary): void {
            $mappingSummary = $this->presetService->import($feedProfile, $payload, $collisionStrategy);
            $ruleSummary = $this->applyRules($feedProfile, $payload['rules'] ?? [], $collisionStrategy, $actor);

            $summary = [
                'mapping_summary' => $mappingSummary['summary'],
                'rule_summary' => $ruleSummary,
            ];
        });

        $this->auditService->record(
            'mapping',
            'mapping_template_applied',
            'Mapping template applied to feed profile.',
            $actor,
            $feedProfile->shop,
            $feedProfile,
            severity: 'warning',
            context: $summary,
            targetLabel: $feedProfile->name
        );

        return $summary;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function previewRules(FeedProfile $feedProfile, array $rows, string $collisionStrategy): array
    {
        $summary = ['create' => 0, 'update' => 0, 'skip' => 0, 'collisions' => 0];

        foreach ($rows as $row) {
            $existing = $this->resolveExistingRule($feedProfile, $row);

            if ($existing === null) {
                $summary['create']++;
                continue;
            }

            $sameTarget = Canonicalizer::fingerprint($existing->target_payload ?? []) === Canonicalizer::fingerprint((array) ($row['target_payload'] ?? []))
                && (string) $existing->target_reference === (string) ($row['target_reference'] ?? null);

            if ($sameTarget) {
                $summary['skip']++;
                continue;
            }

            $summary[match ($collisionStrategy) {
                'overwrite_existing' => 'update',
                'merge_if_safe' => 'collisions',
                default => 'skip',
            }]++;
        }

        return $summary;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function applyRules(FeedProfile $feedProfile, array $rows, string $collisionStrategy, ?User $actor = null): array
    {
        $summary = ['create' => 0, 'update' => 0, 'skip' => 0, 'collisions' => 0];

        foreach ($rows as $row) {
            $existing = $this->resolveExistingRule($feedProfile, $row);
            $payload = [
                'shop_id' => $row['shop_id'] ?? $feedProfile->shop_id,
                'feed_profile_id' => $row['feed_profile_id'] ?? $feedProfile->id,
                'created_by_user_id' => $actor?->id,
                'rule_type' => $row['rule_type'],
                'match_type' => $row['match_type'],
                'source_pattern' => $row['source_pattern'] ?? null,
                'source_normalized' => $row['source_normalized'] ?? Canonicalizer::normalizeKey((string) ($row['source_pattern'] ?? '')),
                'source_attribute_code' => $row['source_attribute_code'] ?? null,
                'source_category_path' => $row['source_category_path'] ?? null,
                'vendor_scope' => $row['vendor_scope'] ?? null,
                'brand_scope' => $row['brand_scope'] ?? null,
                'target_reference' => $row['target_reference'] ?? null,
                'target_label' => $row['target_label'] ?? null,
                'target_payload' => $row['target_payload'] ?? [],
                'explanation' => $row['explanation'] ?? null,
                'priority' => (int) ($row['priority'] ?? 100),
                'is_auto_apply_safe' => (bool) ($row['is_auto_apply_safe'] ?? false),
                'is_active' => true,
            ];

            if ($existing === null) {
                MappingRule::create($payload);
                $summary['create']++;
                continue;
            }

            $sameTarget = Canonicalizer::fingerprint($existing->target_payload ?? []) === Canonicalizer::fingerprint((array) ($payload['target_payload'] ?? []))
                && (string) $existing->target_reference === (string) $payload['target_reference'];

            if ($sameTarget) {
                $summary['skip']++;
                continue;
            }

            if ($collisionStrategy === 'overwrite_existing') {
                $existing->update($payload);
                $summary['update']++;
                continue;
            }

            if ($collisionStrategy === 'merge_if_safe') {
                $summary['collisions']++;
                continue;
            }

            $summary['skip']++;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveExistingRule(FeedProfile $feedProfile, array $row): ?MappingRule
    {
        return MappingRule::query()
            ->where('shop_id', $row['shop_id'] ?? $feedProfile->shop_id)
            ->where('feed_profile_id', $row['feed_profile_id'] ?? $feedProfile->id)
            ->where('rule_type', $row['rule_type'])
            ->where('match_type', $row['match_type'])
            ->where('source_normalized', $row['source_normalized'] ?? Canonicalizer::normalizeKey((string) ($row['source_pattern'] ?? '')))
            ->where('source_attribute_code', $row['source_attribute_code'] ?? null)
            ->where('source_category_path', $row['source_category_path'] ?? null)
            ->first();
    }
}
