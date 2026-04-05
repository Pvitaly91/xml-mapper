<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DemoBootstrapScaleCommand extends Command
{
    protected $signature = 'demo:bootstrap-scale {--products=5000} {--variants-per-product=4} {--fresh} {--label=}';

    protected $description = 'Alias for ops:load-bootstrap to prepare a reproducible scale dataset.';

    public function handle(): int
    {
        return $this->call('ops:load-bootstrap', [
            '--products' => (int) $this->option('products'),
            '--variants-per-product' => (int) $this->option('variants-per-product'),
            '--fresh' => (bool) $this->option('fresh'),
            '--label' => $this->option('label'),
        ]);
    }
}
