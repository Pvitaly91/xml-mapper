<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGovernanceInput;
use App\Models\ApprovalRequest;
use App\Services\Governance\GovernedActionService;
use Illuminate\Console\Command;
use Throwable;

class ApprovalApproveCommand extends Command
{
    use ResolvesGovernanceInput;

    protected $signature = 'approval:approve
        {approvalId : Approval request ID}
        {--note= : Approval note}
        {--by= : Reviewer email or user id}';

    protected $description = 'Approve and execute a pending approval request.';

    public function handle(GovernedActionService $service): int
    {
        $approval = ApprovalRequest::query()->findOrFail((int) $this->argument('approvalId'));

        try {
            $actor = $this->requireActorOption($this->option('by') ? (string) $this->option('by') : null);
            $approval = $service->approve($approval, $actor, $this->option('note') ? (string) $this->option('note') : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Approval #%d moved to %s.', $approval->id, $approval->status));

        return self::SUCCESS;
    }
}
