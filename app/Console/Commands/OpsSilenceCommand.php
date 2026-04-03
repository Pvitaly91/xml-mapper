<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Ops\SilenceWindowService;
use Illuminate\Console\Command;

class OpsSilenceCommand extends Command
{
    protected $signature = 'ops:silence {feedProfileId : Feed profile ID} {--from= : Silence start timestamp} {--to= : Silence end timestamp} {--severity=critical : Minimum severity that should still break through silence} {--reason= : Silence reason}';

    protected $description = 'Start or update a silence window for a feed profile.';

    public function handle(SilenceWindowService $service): int
    {
        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;

        if (blank($reason)) {
            $this->error('The --reason option is required.');

            return self::INVALID;
        }

        $window = $service->start(
            $feedProfile,
            $service->parse($this->option('from') !== null ? (string) $this->option('from') : null, $feedProfile),
            $service->parse($this->option('to') !== null ? (string) $this->option('to') : null, $feedProfile),
            (string) $this->option('severity'),
            $reason
        );

        $this->info('Silence window active until '.($window->active_to?->toIso8601String() ?: 'manual clear').'.');

        return self::SUCCESS;
    }
}
