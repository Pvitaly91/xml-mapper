<?php

namespace App\Console\Commands;

use App\Models\MerchantLaunch;
use App\Models\MerchantLaunchObservation;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Console\Command;
use Illuminate\Validation\Rule;

class LaunchObserveCommand extends Command
{
    protected $signature = 'launch:observe {launchId : Merchant launch ID} {type : Observation type} {--severity=medium : Observation severity} {--note= : Observation note}';

    protected $description = 'Record a live launch observation.';

    public function handle(MerchantLaunchService $service): int
    {
        $launch = MerchantLaunch::findOrFail((int) $this->argument('launchId'));
        $validator = validator([
            'type' => $this->argument('type'),
            'severity' => $this->option('severity'),
            'note' => $this->option('note'),
        ], [
            'type' => ['required', Rule::in(MerchantLaunchObservation::types())],
            'severity' => ['required', Rule::in(MerchantLaunchObservation::severities())],
            'note' => ['required', 'string', 'max:4000'],
        ]);
        $validated = $validator->validate();
        $observation = $service->addObservation($launch, $validated);

        $this->line(json_encode($observation->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
