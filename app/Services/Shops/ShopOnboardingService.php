<?php

namespace App\Services\Shops;

use App\Models\CategoryMapping;
use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Models\KastaAttribute;
use App\Models\KastaAttributeValue;
use App\Models\KastaCategory;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\User;

class ShopOnboardingService
{
    public const STEP_SHOP = 'shop';

    public const STEP_SOURCE_DRIVER = 'source_driver';

    public const STEP_SOURCE_CONNECTION = 'source_connection';

    public const STEP_TEST_CONNECTION = 'test_connection';

    public const STEP_DICTIONARIES = 'dictionaries';

    public const STEP_FEED_PROFILE = 'feed_profile';

    public const STEP_FIRST_SYNC = 'first_sync';

    public const STEP_MAPPING_BOOTSTRAP = 'mapping_bootstrap';

    public const STEP_BUILD_CANDIDATE = 'build_candidate';

    public const STEP_RELEASE_CENTER = 'release_center';

    public function __construct(
        private readonly ShopOnboardingStateService $stateService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(User $user): array
    {
        $state = $this->stateService->state($user);
        $shop = $user->shop;
        $sourceConnection = $shop?->sourceConnections()->latest('id')->first();
        $feedProfile = $shop?->feedProfiles()->latest('id')->first();
        $latestGeneration = $feedProfile?->latestGeneration;
        $steps = [
            $this->stepShop($shop, $state),
            $this->stepSourceDriver($sourceConnection, $state),
            $this->stepSourceConnection($sourceConnection),
            $this->stepTestConnection($sourceConnection),
            $this->stepDictionaries(),
            $this->stepFeedProfile($feedProfile),
            $this->stepFirstSync($sourceConnection),
            $this->stepMappingBootstrap($feedProfile),
            $this->stepBuildCandidate($latestGeneration),
            $this->stepReleaseCenter($feedProfile, $latestGeneration, $state),
        ];

        $currentStep = collect($steps)->firstWhere('status', 'current')['key']
            ?? collect($steps)->firstWhere('status', 'blocked')['key']
            ?? collect($steps)->firstWhere('status', 'pending')['key']
            ?? self::STEP_RELEASE_CENTER;

        return [
            'state' => $state,
            'shop' => $shop,
            'source_connection' => $sourceConnection,
            'feed_profile' => $feedProfile,
            'latest_generation' => $latestGeneration,
            'current_step' => $currentStep,
            'completed' => collect($steps)->every(fn (array $step) => $step['status'] === 'completed'),
            'steps' => $this->normalizeStepStatuses($steps),
        ];
    }

    public function markReleaseCenterOpened(User $user): array
    {
        return $this->stateService->markStepCompleted($user, self::STEP_RELEASE_CENTER);
    }

    private function stepShop(?object $shop, array $state): array
    {
        $completed = $shop !== null && filled($shop->name) && filled($shop->slug);

        return $this->step(
            self::STEP_SHOP,
            'Create or edit shop',
            $completed,
            blockedReason: null,
            nextSteps: $completed ? [] : ['Fill shop name, slug, locale and timezone.'],
            state: $state
        );
    }

    private function stepSourceDriver(?SourceConnection $sourceConnection, array $state): array
    {
        $completed = $sourceConnection !== null || filled($state['selected_driver'] ?? null);

        return $this->step(
            self::STEP_SOURCE_DRIVER,
            'Choose source driver',
            $completed,
            blockedReason: null,
            nextSteps: $completed ? [] : ['Choose Prom YML or Prom API before configuring the source.'],
            state: $state
        );
    }

    private function stepSourceConnection(?SourceConnection $sourceConnection): array
    {
        $completed = $sourceConnection instanceof SourceConnection;
        $blockedReason = $completed ? null : 'Source connection is not configured yet.';

        return [
            'key' => self::STEP_SOURCE_CONNECTION,
            'label' => 'Configure source connection',
            'status' => $completed ? 'completed' : 'blocked',
            'blocking_reason' => $blockedReason,
            'next_steps' => $completed ? [] : ['Save source connection credentials and sync interval.'],
        ];
    }

    private function stepTestConnection(?SourceConnection $sourceConnection): array
    {
        if (! $sourceConnection instanceof SourceConnection) {
            return [
                'key' => self::STEP_TEST_CONNECTION,
                'label' => 'Test connection',
                'status' => 'blocked',
                'blocking_reason' => 'Create the source connection before testing it.',
                'next_steps' => ['Complete the source connection step first.'],
            ];
        }

        $completed = $sourceConnection->last_connection_check_status === SourceConnection::CHECK_STATUS_OK;

        return [
            'key' => self::STEP_TEST_CONNECTION,
            'label' => 'Test connection',
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : ($sourceConnection->last_connection_check_message ?: 'Connection has not been tested successfully yet.'),
            'next_steps' => $completed ? [] : ['Run Test connection and fix auth or network issues if it fails.'],
        ];
    }

    private function stepDictionaries(): array
    {
        $completed = KastaCategory::query()->exists()
            && KastaAttribute::query()->exists()
            && KastaAttributeValue::query()->exists();

        return [
            'key' => self::STEP_DICTIONARIES,
            'label' => 'Ensure Kasta dictionaries imported',
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : 'Kasta dictionaries are not imported yet.',
            'next_steps' => $completed ? [] : ['Open dictionaries import and load categories, attributes and values.'],
        ];
    }

    private function stepFeedProfile(?FeedProfile $feedProfile): array
    {
        $completed = $feedProfile instanceof FeedProfile;

        return [
            'key' => self::STEP_FEED_PROFILE,
            'label' => 'Create default feed profile',
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : 'Default feed profile is missing.',
            'next_steps' => $completed ? [] : ['Create the default Kasta feed profile for this shop.'],
        ];
    }

    private function stepFirstSync(?SourceConnection $sourceConnection): array
    {
        if (! $sourceConnection instanceof SourceConnection) {
            return [
                'key' => self::STEP_FIRST_SYNC,
                'label' => 'Run first sync',
                'status' => 'blocked',
                'blocking_reason' => 'Source connection is required before the first sync.',
                'next_steps' => ['Configure the source connection first.'],
            ];
        }

        $latestImport = $sourceConnection->latestImport;
        $completed = $latestImport instanceof SourceImport
            && $latestImport->status === SourceImport::STATUS_NORMALIZED;

        return [
            'key' => self::STEP_FIRST_SYNC,
            'label' => 'Run first sync',
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : ($sourceConnection->last_sync_message ?: 'No successful normalized sync yet.'),
            'next_steps' => $completed ? [] : ['Run a sync and wait for normalization to finish.'],
        ];
    }

    private function stepMappingBootstrap(?FeedProfile $feedProfile): array
    {
        if (! $feedProfile instanceof FeedProfile) {
            return [
                'key' => self::STEP_MAPPING_BOOTSTRAP,
                'label' => 'Run initial automap and suggestions',
                'status' => 'blocked',
                'blocking_reason' => 'Feed profile is required before mapping bootstrap.',
                'next_steps' => ['Create the default feed profile first.'],
            ];
        }

        $completed = CategoryMapping::query()->where('feed_profile_id', $feedProfile->id)->exists()
            || $feedProfile->items()->whereIn('status', ['ready', 'published'])->exists();

        return [
            'key' => self::STEP_MAPPING_BOOTSTRAP,
            'label' => 'Run initial automap and suggestions',
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : 'Initial mapping bootstrap has not been applied yet.',
            'next_steps' => $completed ? [] : ['Run category automap and mapping suggestions to seed the first pass.'],
        ];
    }

    private function stepBuildCandidate(?FeedGeneration $latestGeneration): array
    {
        $completed = $latestGeneration instanceof FeedGeneration
            && in_array($latestGeneration->release_status, [
                FeedGeneration::RELEASE_STATUS_CANDIDATE,
                FeedGeneration::RELEASE_STATUS_APPROVED,
                FeedGeneration::RELEASE_STATUS_PUBLISHED,
            ], true);

        return [
            'key' => self::STEP_BUILD_CANDIDATE,
            'label' => 'Build first release candidate',
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : 'No release candidate generation exists yet.',
            'next_steps' => $completed ? [] : ['Build the feed and mark the latest generation as candidate.'],
        ];
    }

    private function stepReleaseCenter(?FeedProfile $feedProfile, ?FeedGeneration $latestGeneration, array $state): array
    {
        if (! $feedProfile instanceof FeedProfile || ! $latestGeneration instanceof FeedGeneration) {
            return [
                'key' => self::STEP_RELEASE_CENTER,
                'label' => 'Open release center',
                'status' => 'blocked',
                'blocking_reason' => 'Release center becomes relevant after the first candidate exists.',
                'next_steps' => ['Build the first candidate generation first.'],
            ];
        }

        $completed = array_key_exists(self::STEP_RELEASE_CENTER, $state['completed_steps'] ?? []);

        return [
            'key' => self::STEP_RELEASE_CENTER,
            'label' => 'Open release center',
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : 'Open the release center to review readiness, approve and publish.',
            'next_steps' => $completed ? [] : ['Open the release center and continue with approval or publish.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function step(string $key, string $label, bool $completed, ?string $blockedReason, array $nextSteps, array $state): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $completed ? 'completed' : 'pending',
            'blocking_reason' => $completed ? null : $blockedReason,
            'next_steps' => $completed ? [] : $nextSteps,
            'state' => $state,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function normalizeStepStatuses(array $steps): array
    {
        $currentAssigned = false;

        return collect($steps)->map(function (array $step) use (&$currentAssigned): array {
            if ($step['status'] === 'completed') {
                return $step;
            }

            if (! $currentAssigned && in_array($step['status'], ['pending', 'blocked'], true)) {
                $step['status'] = 'current';
                $currentAssigned = true;
            }

            return $step;
        })->all();
    }
}
