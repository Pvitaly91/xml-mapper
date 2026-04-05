<?php

namespace App\Console\Commands;

use App\Services\Demo\FunctionalMerchantDemoService;
use Illuminate\Console\Command;

class DemoFunctionalExportCommand extends Command
{
    protected $signature = 'demo:functional-export
        {--fresh : Rebuild the current database before running the functional merchant flow}
        {--json : Output the flow summary as JSON}';

    protected $description = 'Run a reproducible merchant-ready functional flow from source import to mapped XML artifact.';

    public function handle(FunctionalMerchantDemoService $service): int
    {
        try {
            $summary = $service->run((bool) $this->option('fresh'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Functional merchant demo flow completed.');
        $this->line('Shop: '.data_get($summary, 'shop.slug'));
        $this->line('Feed profile: '.data_get($summary, 'feed_profile.code'));
        $this->line('Initial generation: #'.data_get($summary, 'generations.initial.id'));
        $this->line('Post-mapping generation: #'.data_get($summary, 'generations.post_mapping.id'));
        $this->line('Final generation: #'.data_get($summary, 'generations.final.id'));
        $this->line('Final artifact: '.data_get($summary, 'generations.final.artifact_path'));
        $this->line('Summary file: '.$summary['summary_path']);

        return self::SUCCESS;
    }
}
