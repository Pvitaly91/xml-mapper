<?php

namespace Tests\Unit\Mapping;

use App\Models\MappingRule;
use App\Services\Mappings\Automation\MappingRuleEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class MappingRuleEngineServiceTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_exact_alias_regex_and_path_rules_match_deterministically(): void
    {
        $shop = $this->createShop();
        $admin = $this->createAdminUser($shop);
        $connection = $this->createSourceConnection($shop);
        $feedProfile = $this->createFeedProfile($connection, $admin);
        $categoryA = $this->createKastaCategory([
            'external_id' => 'CAT-A',
            'name' => 'T-Shirts',
            'full_path' => 'Apparel > Tops > T-Shirts',
            'rz_id' => '2001',
        ]);
        $categoryB = $this->createKastaCategory([
            'external_id' => 'CAT-B',
            'name' => 'Shirts',
            'full_path' => 'Apparel > Shirts',
            'rz_id' => '2002',
        ]);

        MappingRule::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'created_by_user_id' => $admin->id,
            'rule_type' => MappingRule::TYPE_CATEGORY,
            'match_type' => MappingRule::MATCH_EXACT,
            'source_pattern' => 'T-Shirts',
            'source_normalized' => 't_shirts',
            'target_reference' => 'CAT-A',
            'target_payload' => ['target_id' => $categoryA->id],
            'priority' => 100,
            'is_auto_apply_safe' => true,
        ]);

        MappingRule::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'created_by_user_id' => $admin->id,
            'rule_type' => MappingRule::TYPE_CATEGORY,
            'match_type' => MappingRule::MATCH_ALIAS,
            'source_pattern' => 'tee',
            'source_normalized' => 'tee',
            'target_reference' => 'CAT-A',
            'target_payload' => ['target_id' => $categoryA->id],
            'priority' => 90,
            'is_auto_apply_safe' => true,
        ]);

        MappingRule::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'created_by_user_id' => $admin->id,
            'rule_type' => MappingRule::TYPE_CATEGORY,
            'match_type' => MappingRule::MATCH_REGEX,
            'source_pattern' => '/shirt/i',
            'target_reference' => 'CAT-B',
            'target_payload' => ['target_id' => $categoryB->id],
            'priority' => 70,
            'is_auto_apply_safe' => false,
        ]);

        MappingRule::create([
            'shop_id' => $shop->id,
            'feed_profile_id' => $feedProfile->id,
            'created_by_user_id' => $admin->id,
            'rule_type' => MappingRule::TYPE_CATEGORY,
            'match_type' => MappingRule::MATCH_CATEGORY_PATH,
            'source_pattern' => 'apparel_tops',
            'source_normalized' => 'apparel_tops',
            'source_category_path' => 'Apparel > Tops',
            'target_reference' => 'CAT-A',
            'target_payload' => ['target_id' => $categoryA->id],
            'priority' => 80,
            'is_auto_apply_safe' => false,
        ]);

        $targets = [
            ['id' => $categoryA->id, 'reference' => 'CAT-A', 'label' => $categoryA->full_path, 'normalized_candidates' => ['t_shirts', 'apparel_tops_t_shirts']],
            ['id' => $categoryB->id, 'reference' => 'CAT-B', 'label' => $categoryB->full_path, 'normalized_candidates' => ['shirts', 'apparel_shirts']],
        ];
        $service = app(MappingRuleEngineService::class);

        $exactMatches = $service->explicitMatches($feedProfile, MappingRule::TYPE_CATEGORY, [
            'label' => 'T-Shirts',
            'normalized_candidates' => ['t_shirts'],
            'raw_candidates' => ['T-Shirts'],
        ], $targets, ['source_category_path' => 'Apparel > Tops > T-Shirts']);

        $this->assertSame(MappingRule::MATCH_EXACT, $exactMatches[0]['match_strategy']);
        $this->assertSame($categoryA->id, $exactMatches[0]['target']['id']);

        $aliasMatches = $service->explicitMatches($feedProfile, MappingRule::TYPE_CATEGORY, [
            'label' => 'tee',
            'normalized_candidates' => ['tee'],
            'raw_candidates' => ['tee'],
        ], $targets, ['source_category_path' => 'Apparel > Tops']);

        $this->assertSame(MappingRule::MATCH_ALIAS, $aliasMatches[0]['match_strategy']);
        $this->assertStringContainsString('alias', $aliasMatches[0]['explanation']);

        $regexMatches = $service->explicitMatches($feedProfile, MappingRule::TYPE_CATEGORY, [
            'label' => 'Oxford Shirt',
            'normalized_candidates' => ['oxford_shirt'],
            'raw_candidates' => ['Oxford Shirt'],
        ], $targets, ['source_category_path' => 'Apparel > Shirts']);

        $this->assertSame(MappingRule::MATCH_REGEX, $regexMatches[0]['match_strategy']);
        $this->assertSame($categoryB->id, $regexMatches[0]['target']['id']);

        $pathMatches = $service->explicitMatches($feedProfile, MappingRule::TYPE_CATEGORY, [
            'label' => 'Summer Tops',
            'normalized_candidates' => ['summer_tops'],
            'raw_candidates' => ['Summer Tops'],
        ], $targets, ['source_category_path' => 'Apparel > Tops > Summer']);

        $this->assertSame(MappingRule::MATCH_CATEGORY_PATH, $pathMatches[0]['match_strategy']);
        $this->assertSame($categoryA->id, $pathMatches[0]['target']['id']);
    }
}
