<?php

namespace Tests\Unit\Source;

use App\Models\SourceConnection;
use App\Services\Source\Drivers\PromApiSourceDriver;
use App\Services\Source\Drivers\PromYmlSourceDriver;
use App\Services\Source\SourceDriverRegistry;
use Tests\TestCase;

class SourceDriverRegistryTest extends TestCase
{
    public function test_prom_yml_path_resolves_existing_driver(): void
    {
        $registry = app(SourceDriverRegistry::class);
        $connection = new SourceConnection(['driver' => SourceConnection::DRIVER_PROM_YML]);

        $this->assertInstanceOf(PromYmlSourceDriver::class, $registry->forConnection($connection));
    }

    public function test_prom_api_path_resolves_prom_api_driver(): void
    {
        $registry = app(SourceDriverRegistry::class);
        $connection = new SourceConnection(['driver' => SourceConnection::DRIVER_PROM_API]);

        $this->assertInstanceOf(PromApiSourceDriver::class, $registry->forConnection($connection));
    }
}
