<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Console\Commands\Concerns\ResolvesMappingActor;
use App\Models\FeedProfile;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Mappings\Automation\MappingTemplateLibraryService;
use Illuminate\Console\Command;

class MappingTemplateApplyCommand extends Command
{
    use ResolvesGovernanceInput;
    use ResolvesMappingActor;

    protected $signature = 'mapping:template:apply {feedProfileId} {--file=} {--dry-run} {--by=}';

    protected $description = 'Apply a mapping template JSON file to a feed profile.';

    public function handle(MappingTemplateLibraryService $service, GovernedActionService $governedActionService): int
    {
        $feedProfile = FeedProfile::query()->with('user', 'shop')->findOrFail((int) $this->argument('feedProfileId'));
        $path = (string) $this->option('file');

        if ($path === '' || ! is_file($path)) {
            $this->error('Pass --file=/absolute/path/to/template.json');

            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $collisionStrategy = (string) ($payload['collision_strategy'] ?? 'skip_existing');

        if ((bool) $this->option('dry-run')) {
            $preview = $service->previewApply($feedProfile, $payload, $collisionStrategy);
            $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $actor = $this->resolveActorForFeedProfile($feedProfile, $this->option('by'));
        $needsGovernance = (($payload['template_scope'] ?? '') === 'global') || $collisionStrategy === 'overwrite_existing';

        if ($needsGovernance) {
            $result = $governedActionService->dispatch(
                ApprovalPolicyService::ACTION_MAPPING_TEMPLATE_APPLY,
                $actor,
                $feedProfile->shop,
                $feedProfile,
                [
                    'feed_profile_id' => $feedProfile->id,
                    'template_payload' => $payload,
                    'collision_strategy' => $collisionStrategy,
                ],
                [
                    'feed_profile_id' => $feedProfile->id,
                    'collision_strategy' => $collisionStrategy,
                ],
                'CLI template apply',
                null,
                $feedProfile->name
            );

            $this->info($result->message ?: $result->status);

            return self::SUCCESS;
        }

        $summary = $service->applyPayload($feedProfile, $payload, $collisionStrategy, $actor);
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
