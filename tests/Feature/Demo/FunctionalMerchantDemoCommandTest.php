<?php

namespace Tests\Feature\Demo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FunctionalMerchantDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_functional_demo_command_reaches_publish_ready_mapped_xml(): void
    {
        $summaryPath = storage_path('app/demo/functional-export-summary.json');
        File::delete($summaryPath);

        $status = Artisan::call('demo:functional-export', ['--json' => true]);

        $this->assertSame(0, $status);
        $this->assertFileExists($summaryPath);

        $summary = json_decode((string) File::get($summaryPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('functional-demo-shop', data_get($summary, 'shop.slug'));
        $this->assertTrue((bool) data_get($summary, 'generations.final.publish_ready'));
        $this->assertSame('approved', data_get($summary, 'generations.final.release_status'));
        $this->assertGreaterThan(data_get($summary, 'generations.final.excluded_items'), data_get($summary, 'generations.initial.excluded_items'));
        $this->assertSame(0, data_get($summary, 'generations.final.excluded_items'));
        Storage::disk(config('feed_mediator.storage_disk'))->assertExists((string) data_get($summary, 'generations.final.artifact_path'));
    }
}
