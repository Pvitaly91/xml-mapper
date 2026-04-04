<?php

namespace App\Console\Commands;

use App\Models\MerchantLaunch;
use App\Models\MerchantLaunchDefect;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Console\Command;
use Illuminate\Validation\Rule;

class LaunchDefectCommand extends Command
{
    protected $signature = 'launch:defect {launchId : Merchant launch ID} {type : Defect type} {--severity=medium : Defect severity} {--note= : Defect note}';

    protected $description = 'Open a post-launch defect for the given live launch.';

    public function handle(MerchantLaunchService $service): int
    {
        $launch = MerchantLaunch::findOrFail((int) $this->argument('launchId'));
        $validator = validator([
            'type' => $this->argument('type'),
            'severity' => $this->option('severity'),
            'note' => $this->option('note'),
        ], [
            'type' => ['required', Rule::in(MerchantLaunchDefect::types())],
            'severity' => ['required', Rule::in(MerchantLaunchDefect::severities())],
            'note' => ['required', 'string', 'max:4000'],
        ]);
        $validated = $validator->validate();
        $defect = $service->addDefect($launch, $validated);

        $this->line(json_encode($defect->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
