<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesMappingScope;
use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Console\Commands\Concerns\ResolvesMappingActor;
use App\Models\FeedProfile;
use App\Models\MappingBatch;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Mappings\Automation\MappingBatchService;
use Illuminate\Console\Command;

class MappingApplySuggestionsCommand extends Command
{
    use ParsesMappingScope;
    use ResolvesGovernanceInput;
    use ResolvesMappingActor;

    protected $signature = 'mapping:apply-suggestions {feedProfileId} {--type=category} {--threshold=0.9} {--dry-run} {--strategy=safe} {--scope=} {--by=}';

    protected $description = 'Plan and optionally execute a deterministic mapping auto-apply batch.';

    public function handle(MappingBatchService $batchService, GovernedActionService $governedActionService): int
    {
        $feedProfile = FeedProfile::query()->with('user', 'shop')->findOrFail((int) $this->argument('feedProfileId'));
        $actor = $this->resolveActorForFeedProfile($feedProfile, $this->option('by'));
        $batch = $batchService->planSuggestionBatch(
            $feedProfile,
            (string) $this->option('type'),
            (float) $this->option('threshold'),
            (string) $this->option('strategy'),
            $this->parseMappingScope($this->option('scope')),
            $actor,
            (bool) $this->option('dry-run')
        );

        if ($batch->status === MappingBatch::STATUS_DRY_RUN) {
            $this->info(sprintf('Dry-run batch #%d planned.', $batch->id));
            $this->line(json_encode($batch->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($batch->risk_level !== 'standard') {
            $result = $governedActionService->dispatch(
                ApprovalPolicyService::ACTION_MAPPING_BULK_APPLY,
                $actor,
                $feedProfile->shop,
                $feedProfile,
                ['mapping_batch_id' => $batch->id],
                [
                    'batch_id' => $batch->id,
                    'mapping_type' => $batch->mapping_type,
                    'risk_level' => $batch->risk_level,
                    'strategy' => $batch->strategy,
                    'planned' => ($batch->summary ?? [])['planned'] ?? 0,
                ],
                'CLI mapping auto-apply',
                null,
                $feedProfile->name
            );

            if ($result->status === 'approval_required' && $result->approvalRequest) {
                $batch->update([
                    'approval_request_id' => $result->approvalRequest->id,
                    'status' => MappingBatch::STATUS_PENDING_APPROVAL,
                ]);
                $this->warn(sprintf('Approval required. Batch #%d linked to approval #%d.', $batch->id, $result->approvalRequest->id));

                return self::SUCCESS;
            }

            $this->info(sprintf('Governed batch #%d executed.', $batch->id));

            return self::SUCCESS;
        }

        $batch = $batchService->executeBatch($batch, $actor);
        $this->info(sprintf('Batch #%d executed.', $batch->id));
        $this->line(json_encode($batch->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
