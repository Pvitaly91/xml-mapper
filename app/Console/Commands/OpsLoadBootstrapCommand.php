<?php

namespace App\Console\Commands;

use App\Services\Ops\PerformanceWorkflowService;
use Illuminate\Console\Command;

class OpsLoadBootstrapCommand extends Command
{
    protected $signature = 'ops:load-bootstrap {--products=5000} {--variants-per-product=4} {--fresh} {--label=}';

    protected $description = 'Generate deterministic large-catalog fixtures and bootstrap a scale-ready shop/feed profile.';

    public function handle(
        PerformanceWorkflowService $service,
    ): int {
        $products = max(1, (int) $this->option('products'));
        $variantsPerProduct = max(1, (int) $this->option('variants-per-product'));
        $completed = $service->runLoadBootstrap(
            $products,
            $variantsPerProduct,
            (bool) $this->option('fresh'),
            null,
            $this->option('label') ? (string) $this->option('label') : null,
        );

        if ($completed->status === 'failed') {
            $this->error(implode(PHP_EOL, (array) ($completed->errors ?? ['Scale bootstrap failed.'])));

            return self::FAILURE;
        }

        $this->info('Scale bootstrap finished.');
        $this->line('performance_run_id: '.$completed->id);
        $this->line('status: '.$completed->status);
        $this->line('budget_status: '.$completed->budget_status);
        $this->line('duration_ms: '.$completed->duration_ms);
        $this->line('processed_products: '.$completed->processed_products);
        $this->line('processed_variants: '.$completed->processed_variants);

        return self::SUCCESS;
    }
}
