<?php

namespace App\Console\Commands;

use App\Services\Demo\E2eDemoBootstrapService;
use Illuminate\Console\Command;

class DemoBootstrapE2eCommand extends Command
{
    protected $signature = 'demo:bootstrap-e2e
        {--fresh : Rebuild the current demo database from scratch before bootstrapping}
        {--json : Output a machine-readable safe summary}';

    protected $description = 'Bootstrap reproducible demo data and local-only manifests for browser E2E and manual operator QA.';

    public function handle(E2eDemoBootstrapService $service): int
    {
        try {
            $result = $service->bootstrap((bool) $this->option('fresh'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('E2E demo data is ready.');
        $this->line('Manifest: '.$result['manifest_path']);
        $this->line('Safe summary: '.$result['summary_path']);
        $this->line('Main shop: '.data_get($result['summary'], 'shops.main.name').' ('.data_get($result['summary'], 'shops.main.slug').')');
        $this->line('Feed profile: '.data_get($result['summary'], 'entities.feed_profile.code'));
        $this->line('Platform admin: '.data_get($result['summary'], 'users.platform_admin.email'));
        $this->line('Reviewer: '.data_get($result['summary'], 'users.reviewer.email'));
        $this->line('Operator: '.data_get($result['summary'], 'users.operator.email'));
        $this->line('Invited shop admin: '.data_get($result['summary'], 'users.invited_shop_admin.email'));
        $this->warn('Manifest contains local-only demo credentials and MFA material. Do not upload it to CI artifacts or external reports.');

        return self::SUCCESS;
    }
}
