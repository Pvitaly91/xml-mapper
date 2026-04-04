<?php

namespace App\Console\Commands;

use App\Services\Governance\ComplianceReportService;
use Illuminate\Console\Command;

class ComplianceReportCommand extends Command
{
    protected $signature = 'compliance:report
        {--shop= : Shop ID}
        {--user= : User ID}
        {--from= : Start date YYYY-MM-DD}
        {--to= : End date YYYY-MM-DD}';

    protected $description = 'Generate a governance compliance export report.';

    public function handle(ComplianceReportService $service): int
    {
        $report = $service->export([
            'shop_id' => $this->option('shop'),
            'user_id' => $this->option('user'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
        ]);

        $this->line(json_encode([
            'path' => $report['path'],
            'absolute_path' => $report['absolute_path'],
            'audits' => count($report['payload']['audits'] ?? []),
            'approvals' => count($report['payload']['approvals'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
