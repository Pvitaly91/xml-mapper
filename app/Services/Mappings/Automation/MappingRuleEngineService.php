<?php

namespace App\Services\Mappings\Automation;

use App\Models\FeedProfile;
use App\Models\MappingRule;
use App\Support\Canonicalizer;
use Illuminate\Support\Collection;

class MappingRuleEngineService
{
    /**
     * @return Collection<int, MappingRule>
     */
    public function rulesFor(FeedProfile $feedProfile, string $ruleType): Collection
    {
        return MappingRule::query()
            ->where('rule_type', $ruleType)
            ->where('is_active', true)
            ->where(function ($query) use ($feedProfile): void {
                $query->whereNull('shop_id')
                    ->orWhere('shop_id', $feedProfile->shop_id);
            })
            ->where(function ($query) use ($feedProfile): void {
                $query->whereNull('feed_profile_id')
                    ->orWhere('feed_profile_id', $feedProfile->id);
            })
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<int, array<string, mixed>>  $targets
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public function explicitMatches(
        FeedProfile $feedProfile,
        string $ruleType,
        array $source,
        array $targets,
        array $context = []
    ): array {
        $matches = [];

        foreach ($this->rulesFor($feedProfile, $ruleType) as $rule) {
            if (! $this->ruleAppliesToContext($rule, $context) || ! $this->matchesRule($rule, $source, $context)) {
                continue;
            }

            $target = $this->resolveExplicitTarget($rule, $targets);

            if ($target === null) {
                continue;
            }

            $matches[] = $this->candidate(
                target: $target,
                matchStrategy: $rule->match_type,
                confidence: $this->confidence($rule->match_type, true),
                explanation: $rule->explanation ?: $this->defaultExplanation($rule->match_type, $source['label'] ?? null, $target['label'] ?? null),
                evidence: array_filter([
                    'rule_id' => $rule->id,
                    'source_pattern' => $rule->source_pattern,
                    'source_category_path' => $rule->source_category_path,
                    'vendor_scope' => $rule->vendor_scope,
                    'brand_scope' => $rule->brand_scope,
                ], fn ($value) => $value !== null && $value !== ''),
                safe: (bool) $rule->is_auto_apply_safe,
                origin: 'rule',
                priority: (int) $rule->priority
            );
        }

        return $this->sortMatches($matches);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function heuristicCandidate(
        string $matchStrategy,
        array $target,
        array $evidence = [],
        ?string $sourceLabel = null,
        bool $safe = true,
        int $priority = 0
    ): array {
        return $this->candidate(
            target: $target,
            matchStrategy: $matchStrategy,
            confidence: $this->confidence($matchStrategy, false),
            explanation: $this->defaultExplanation($matchStrategy, $sourceLabel, $target['label'] ?? null),
            evidence: $evidence,
            safe: $safe && $this->confidence($matchStrategy, false) >= (float) config('feed_mediator.mapping_automation.auto_apply_confidence', 0.9),
            origin: 'heuristic',
            priority: $priority
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    public function sortMatches(array $matches): array
    {
        usort($matches, function (array $left, array $right): int {
            $leftTuple = [$left['confidence'], $left['priority'], $left['origin'] === 'rule' ? 1 : 0, $left['target']['label'] ?? ''];
            $rightTuple = [$right['confidence'], $right['priority'], $right['origin'] === 'rule' ? 1 : 0, $right['target']['label'] ?? ''];

            return $rightTuple <=> $leftTuple;
        });

        return array_values($matches);
    }

    public function confidence(string $matchType, bool $explicit): float
    {
        $base = match ($matchType) {
            MappingRule::MATCH_RZ_ID, MappingRule::MATCH_EXACT => 0.95,
            MappingRule::MATCH_ALIAS => 0.9,
            MappingRule::MATCH_CATEGORY_PATH => 0.82,
            MappingRule::MATCH_STARTS_WITH => 0.76,
            MappingRule::MATCH_ENDS_WITH => 0.72,
            MappingRule::MATCH_CONTAINS => 0.68,
            MappingRule::MATCH_REGEX => 0.62,
            default => 0.5,
        };

        return min(0.99, $explicit ? $base : max(0.55, $base - 0.02));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $context
     */
    private function matchesRule(MappingRule $rule, array $source, array $context): bool
    {
        $pattern = Canonicalizer::normalizeText((string) ($rule->source_pattern ?? ''));
        $normalizedPattern = $rule->source_normalized ?: Canonicalizer::normalizeKey((string) $pattern);
        $normalizedCandidates = collect($source['normalized_candidates'] ?? [])->filter()->values()->all();
        $rawCandidates = collect($source['raw_candidates'] ?? [])->filter()->values()->all();

        return match ($rule->match_type) {
            MappingRule::MATCH_EXACT, MappingRule::MATCH_ALIAS => in_array($normalizedPattern, $normalizedCandidates, true),
            MappingRule::MATCH_RZ_ID => $pattern !== null && in_array($pattern, $rawCandidates, true),
            MappingRule::MATCH_STARTS_WITH => $this->stringMatch($normalizedCandidates, $normalizedPattern, 'starts_with'),
            MappingRule::MATCH_ENDS_WITH => $this->stringMatch($normalizedCandidates, $normalizedPattern, 'ends_with'),
            MappingRule::MATCH_CONTAINS => $this->stringMatch($normalizedCandidates, $normalizedPattern, 'contains'),
            MappingRule::MATCH_CATEGORY_PATH => $this->matchCategoryPath($context, $normalizedPattern),
            MappingRule::MATCH_REGEX => $this->regexMatch($rawCandidates, (string) ($rule->source_pattern ?? '')),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ruleAppliesToContext(MappingRule $rule, array $context): bool
    {
        if (filled($rule->source_attribute_code) && Canonicalizer::normalizeKey($rule->source_attribute_code) !== Canonicalizer::normalizeKey((string) ($context['source_attribute_code'] ?? ''))) {
            return false;
        }

        if (filled($rule->vendor_scope) && Canonicalizer::normalizeKey($rule->vendor_scope) !== Canonicalizer::normalizeKey((string) ($context['vendor'] ?? ''))) {
            return false;
        }

        if (filled($rule->brand_scope) && Canonicalizer::normalizeKey($rule->brand_scope) !== Canonicalizer::normalizeKey((string) ($context['brand'] ?? ''))) {
            return false;
        }

        if (filled($rule->source_category_path)) {
            $contextPath = Canonicalizer::normalizeKey((string) ($context['source_category_path'] ?? ''));
            $rulePath = Canonicalizer::normalizeKey($rule->source_category_path);

            if ($contextPath === 'undefined' || ! str_contains($contextPath, $rulePath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     * @return array<string, mixed>|null
     */
    private function resolveExplicitTarget(MappingRule $rule, array $targets): ?array
    {
        $targetId = $rule->target_payload['target_id'] ?? null;

        if ($targetId !== null) {
            foreach ($targets as $target) {
                if ((string) ($target['id'] ?? '') === (string) $targetId) {
                    return $target;
                }
            }
        }

        $reference = Canonicalizer::normalizeKey((string) ($rule->target_reference ?? ''));

        if ($reference === 'undefined') {
            return null;
        }

        foreach ($targets as $target) {
            $targetCandidates = collect($target['normalized_candidates'] ?? [])->filter()->values()->all();

            if (in_array($reference, $targetCandidates, true) || Canonicalizer::normalizeKey((string) ($target['reference'] ?? '')) === $reference) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function stringMatch(array $candidates, string $pattern, string $mode): bool
    {
        if ($pattern === '' || $pattern === 'undefined') {
            return false;
        }

        foreach ($candidates as $candidate) {
            if ($mode === 'starts_with' && str_starts_with($candidate, $pattern)) {
                return true;
            }

            if ($mode === 'ends_with' && str_ends_with($candidate, $pattern)) {
                return true;
            }

            if ($mode === 'contains' && str_contains($candidate, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function matchCategoryPath(array $context, string $normalizedPattern): bool
    {
        $contextPath = Canonicalizer::normalizeKey((string) ($context['source_category_path'] ?? ''));

        return $contextPath !== 'undefined' && $normalizedPattern !== '' && str_contains($contextPath, $normalizedPattern);
    }

    /**
     * @param  array<int, string>  $rawCandidates
     */
    private function regexMatch(array $rawCandidates, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        set_error_handler(static fn () => true);

        try {
            foreach ($rawCandidates as $candidate) {
                if (@preg_match($pattern, $candidate) === 1) {
                    return true;
                }
            }
        } finally {
            restore_error_handler();
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function candidate(
        array $target,
        string $matchStrategy,
        float $confidence,
        string $explanation,
        array $evidence,
        bool $safe,
        string $origin,
        int $priority
    ): array {
        return [
            'target' => $target,
            'match_strategy' => $matchStrategy,
            'confidence' => round($confidence, 2),
            'explanation' => $explanation,
            'supporting_evidence' => $evidence,
            'safe_for_auto_apply' => $safe,
            'origin' => $origin,
            'priority' => $priority,
        ];
    }

    private function defaultExplanation(string $matchType, ?string $sourceLabel, ?string $targetLabel): string
    {
        $sourceLabel = $sourceLabel ?: 'source value';
        $targetLabel = $targetLabel ?: 'target value';

        return match ($matchType) {
            MappingRule::MATCH_RZ_ID => sprintf('Matched %s to %s by shared RZ identifier.', $sourceLabel, $targetLabel),
            MappingRule::MATCH_EXACT => sprintf('Matched %s to %s by exact normalized text.', $sourceLabel, $targetLabel),
            MappingRule::MATCH_ALIAS => sprintf('Matched %s to %s by explicit alias rule.', $sourceLabel, $targetLabel),
            MappingRule::MATCH_CATEGORY_PATH => sprintf('Matched %s to %s by source category path scope.', $sourceLabel, $targetLabel),
            MappingRule::MATCH_STARTS_WITH => sprintf('Matched %s to %s because the normalized source starts with the rule pattern.', $sourceLabel, $targetLabel),
            MappingRule::MATCH_ENDS_WITH => sprintf('Matched %s to %s because the normalized source ends with the rule pattern.', $sourceLabel, $targetLabel),
            MappingRule::MATCH_CONTAINS => sprintf('Matched %s to %s because the normalized source contains the rule pattern.', $sourceLabel, $targetLabel),
            MappingRule::MATCH_REGEX => sprintf('Matched %s to %s using a deterministic regex rule.', $sourceLabel, $targetLabel),
            default => sprintf('Matched %s to %s.', $sourceLabel, $targetLabel),
        };
    }
}
