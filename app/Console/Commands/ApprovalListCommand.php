<?php

namespace App\Console\Commands;

use App\Services\Governance\ComplianceReportService;
use Illuminate\Console\Command;

class ApprovalListCommand extends Command
{
    protected $signature = 'approval:list {--status=pending : Approval status filter} {--shop= : Shop ID}';

    protected $description = 'List approval requests by status and shop.';

    public function handle(ComplianceReportService $service): int
    {
        $approvals = collect($service->approvals([
            'status' => $this->option('status'),
            'shop_id' => $this->option('shop'),
        ], 200)->items());

        $this->table(
            ['ID', 'Action', 'Status', 'Risk', 'Requester', 'Shop', 'Requested'],
            $approvals->map(fn ($approval) => [
                $approval->id,
                $approval->action,
                $approval->status,
                $approval->classification,
                $approval->requestedBy?->email ?: 'system',
                $approval->shop?->name ?: 'platform',
                optional($approval->requested_at)->format('Y-m-d H:i:s') ?: 'n/a',
            ])->all()
        );

        $this->info('Listed '.$approvals->count().' approval request(s).');

        return self::SUCCESS;
    }
}
