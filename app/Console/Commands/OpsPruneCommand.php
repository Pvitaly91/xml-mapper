<?php

namespace App\Console\Commands;

use App\Services\Ops\PruneService;
use Illuminate\Console\Command;

class OpsPruneCommand extends Command
{
    protected $signature = 'ops:prune';

    protected $description = 'Prune old build artifacts, preview links, smoke checks, feedback artifacts, and QA bundles.';

    public function handle(PruneService $service): int
    {
        $result = $service->run();

        foreach ($result['summary'] as $key => $value) {
            $this->line($key.': '.$value);
        }

        return self::SUCCESS;
    }
}
