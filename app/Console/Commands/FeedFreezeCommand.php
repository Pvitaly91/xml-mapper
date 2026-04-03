<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Feeds\FeedPublishWindowService;
use Illuminate\Console\Command;

class FeedFreezeCommand extends Command
{
    protected $signature = 'feed:freeze
        {feedProfileId : Feed profile ID}
        {--on : Enable freeze mode}
        {--off : Disable freeze mode}
        {--reason= : Reason for the freeze mode change}';

    protected $description = 'Enable or disable freeze mode for a feed profile.';

    public function handle(FeedPublishWindowService $publishWindowService): int
    {
        $turnOn = (bool) $this->option('on');
        $turnOff = (bool) $this->option('off');
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;

        if ($turnOn === $turnOff) {
            $this->error('Use exactly one of --on or --off.');

            return self::INVALID;
        }

        if (blank($reason)) {
            $this->error('The --reason option is required.');

            return self::INVALID;
        }

        $feedProfile = FeedProfile::findOrFail((int) $this->argument('feedProfileId'));
        $publishWindowService->setFreezeMode($feedProfile, $turnOn, $reason);

        $this->info($turnOn ? 'Freeze mode enabled.' : 'Freeze mode disabled.');

        return self::SUCCESS;
    }
}
