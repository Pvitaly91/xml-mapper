<?php

namespace Tests\Feature\Ops;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Contracts\Source\SourceImportServiceInterface;
use App\Exceptions\ProcessAlreadyRunningException;
use App\Jobs\BuildFeedJob;
use App\Jobs\PublishFeedJob;
use App\Jobs\SyncSourceJob;
use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\SourceCategory;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Ops\ProcessLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;

class SchedulerCommandTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_source_sync_due_command_only_queues_due_connections(): void
    {
        Queue::fake();

        $shop = $this->createShop();
        $dueConnection = $this->createSourceConnection($shop, ['code' => 'due-sync', 'next_sync_at' => now()->subMinute()]);
        $this->createSourceConnection($shop, ['code' => 'future-sync', 'next_sync_at' => now()->addHour()]);
        $this->createSourceConnection($shop, ['code' => 'paused-sync', 'status' => SourceConnection::STATUS_PAUSED, 'next_sync_at' => now()->subMinute()]);

        $this->artisan('source:sync --due --queue')->assertSuccessful();
        $this->artisan('source:sync --due --queue')->assertSuccessful();

        Queue::assertPushed(SyncSourceJob::class, 1);
        Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job) => $job->sourceConnectionId === $dueConnection->id && $job->onlyIfDue && $job->queue === config('feed_mediator.queues.imports'));
    }

    public function test_feed_build_due_command_is_idempotent_across_repeated_scheduler_runs(): void
    {
        Queue::fake();

        $shop = $this->createShop();
        $connection = $this->createSourceConnection($shop);
        $dueProfile = $this->createFeedProfile($connection, null, ['code' => 'due-build', 'next_build_at' => now()->subMinute(), 'auto_build' => true]);
        $this->createFeedProfile($connection, null, ['code' => 'future-build', 'next_build_at' => now()->addHour(), 'auto_build' => true]);
        $this->createFeedProfile($connection, null, ['code' => 'manual-build', 'next_build_at' => now()->subMinute(), 'auto_build' => false]);

        $this->artisan('feed:build --due --queue')->assertSuccessful();
        $this->artisan('feed:build --due --queue')->assertSuccessful();

        Queue::assertPushed(BuildFeedJob::class, 1);
        Queue::assertPushed(BuildFeedJob::class, fn (BuildFeedJob $job) => $job->feedProfileId === $dueProfile->id && $job->onlyIfDue && ! $job->publishAfterBuild && $job->queue === config('feed_mediator.queues.feeds'));
    }

    public function test_feed_publish_due_command_resolves_due_profiles_for_all_supported_scenarios(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));
        Queue::fake();

        $dueNeverPublished = $this->seedPublishedFeedProfile(['code' => 'never-published'], publishLatest: false);
        $dueNewerBuilt = $this->seedPublishedFeedProfile(['code' => 'newer-built'], publishLatest: true, createNewerBuiltGeneration: true);
        $dueMissingPublishedFile = $this->seedPublishedFeedProfile(['code' => 'missing-public-file'], publishLatest: true, removePublishedFile: true);
        $notDue = $this->seedPublishedFeedProfile(['code' => 'already-published'], publishLatest: true);

        $this->artisan('feed:publish --due --queue')->assertSuccessful();
        $this->artisan('feed:publish --due --queue')->assertSuccessful();

        Queue::assertPushed(PublishFeedJob::class, 3);
        Queue::assertPushed(PublishFeedJob::class, fn (PublishFeedJob $job) => $job->feedProfileId === $dueNeverPublished->id && $job->onlyIfDue);
        Queue::assertPushed(PublishFeedJob::class, fn (PublishFeedJob $job) => $job->feedProfileId === $dueNewerBuilt->id && $job->feedGenerationId === $dueNewerBuilt->latestGeneration->id);
        Queue::assertPushed(PublishFeedJob::class, fn (PublishFeedJob $job) => $job->feedProfileId === $dueMissingPublishedFile->id);
        Queue::assertNotPushed(PublishFeedJob::class, fn (PublishFeedJob $job) => $job->feedProfileId === $notDue->id);
    }

    public function test_feed_build_is_idempotent_for_the_same_source_import(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['feedProfile' => $feedProfile, 'connection' => $connection] = $this->seedUniqueBuildableCatalog('source-import');
        $sourceImport = SourceImport::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'status' => SourceImport::STATUS_NORMALIZED,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $firstGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile, $sourceImport->id);
        $secondGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh(), $sourceImport->id);

        $this->assertSame($firstGeneration->id, $secondGeneration->id);
        $this->assertDatabaseCount('feed_generations', 1);
    }

    public function test_feed_publish_is_idempotent_for_an_already_published_generation(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        $feedProfile = $this->seedPublishedFeedProfile(['code' => 'published-idempotent'], publishLatest: true);
        $generation = $feedProfile->publishedGeneration;

        $published = app(FeedPublishServiceInterface::class)->publish($feedProfile->fresh(), $generation);

        $this->assertSame($generation->id, $published->id);
        Storage::disk(config('feed_mediator.storage_disk'))->assertExists($feedProfile->published_path);
    }

    public function test_source_sync_is_locked_while_the_same_connection_is_already_running(): void
    {
        $shop = $this->createShop();
        $connection = $this->createSourceConnection($shop);
        $lockService = app(ProcessLockService::class);
        $sourceImportService = app(SourceImportServiceInterface::class);

        $this->expectException(ProcessAlreadyRunningException::class);

        $lockService->runExclusive(
            $lockService->sourceSyncKey($connection->id),
            60,
            'Outer lock should stay held during the assertion.',
            fn () => $sourceImportService->sync($connection),
        );
    }

    private function seedPublishedFeedProfile(array $profileOverrides = [], bool $publishLatest = true, bool $createNewerBuiltGeneration = false, bool $removePublishedFile = false): FeedProfile
    {
        $suffix = $profileOverrides['code'] ?? (string) str()->uuid();
        ['feedProfile' => $feedProfile] = $this->seedUniqueBuildableCatalog($suffix);

        $feedProfile->update($profileOverrides);

        $firstGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile);

        if ($publishLatest) {
            app(FeedPublishServiceInterface::class)->publish($feedProfile, $firstGeneration);
        } else {
            app(FeedReleaseService::class)->markCandidate($firstGeneration);
            app(FeedReleaseService::class)->approve($firstGeneration->fresh());
        }

        if ($createNewerBuiltGeneration) {
            $newerGeneration = app(FeedBuildServiceInterface::class)->build($feedProfile->fresh());
            app(FeedReleaseService::class)->markCandidate($newerGeneration);
            app(FeedReleaseService::class)->approve($newerGeneration->fresh());
        }

        $feedProfile = $feedProfile->fresh(['latestGeneration', 'publishedGeneration']);

        if ($removePublishedFile && $feedProfile->published_path) {
            Storage::disk(config('feed_mediator.storage_disk'))->delete($feedProfile->published_path);
        }

        return $feedProfile->fresh(['latestGeneration', 'publishedGeneration']);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedUniqueBuildableCatalog(string $suffix): array
    {
        $shop = $this->createShop(['slug' => 'shop-'.md5($suffix)]);
        $admin = $this->createAdminUser($shop, ['email' => 'admin-'.md5($suffix).'@example.test']);
        $connection = $this->createSourceConnection($shop, [
            'code' => 'prom-'.md5($suffix),
            'source_url' => 'https://example.test/'.md5($suffix).'.xml',
        ]);
        $feedProfile = $this->createFeedProfile($connection, $admin, ['code' => 'feed-'.md5($suffix)]);

        $sourceCategory = SourceCategory::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'external_id' => 'src-'.md5($suffix),
            'name' => 'Category '.$suffix,
            'full_path' => 'Apparel > '.$suffix,
            'rz_id' => 'rz-src-'.md5($suffix),
            'is_active' => true,
        ]);
        $product = SourceProduct::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_category_id' => $sourceCategory->id,
            'group_key' => 'grp-'.md5($suffix),
            'name' => 'Product '.$suffix,
            'vendor' => 'Acme',
            'article' => 'ART-'.substr(md5($suffix), 0, 8),
            'brand' => 'Acme',
            'description' => 'Product '.$suffix,
            'primary_image_url' => 'https://example.test/'.md5($suffix).'.jpg',
            'images_json' => ['https://example.test/'.md5($suffix).'.jpg'],
            'attributes_snapshot' => ['Material' => 'Cotton'],
            'is_active' => true,
        ]);
        $variant = SourceVariant::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'source_product_id' => $product->id,
            'external_offer_id' => 'SKU-'.substr(md5($suffix), 0, 8),
            'external_sku' => 'SKU-'.substr(md5($suffix), 0, 8),
            'stable_offer_id' => 'ofr_'.substr(md5($suffix), 0, 16),
            'offer_identity_key' => 'identity-'.md5($suffix),
            'export_key_hash' => sha1('export-'.md5($suffix)),
            'title' => 'Variant '.$suffix,
            'price' => 799,
            'currency' => 'UAH',
            'quantity' => 10,
            'is_available' => true,
            'color' => 'Black',
            'size' => 'S',
            'images_json' => ['https://example.test/'.md5($suffix).'.jpg'],
            'attributes_snapshot' => ['Color' => 'Black', 'Size' => 'S'],
            'is_enabled' => true,
        ]);
        $kastaCategory = $this->createKastaCategory([
            'external_id' => 'KASTA-'.strtoupper(substr(md5($suffix), 0, 10)),
            'rz_id' => 'rz-kasta-'.md5($suffix),
            'name' => 'Category '.$suffix,
            'full_path' => 'Apparel > '.$suffix,
        ]);

        CategoryMapping::create([
            'shop_id' => $shop->id,
            'source_connection_id' => $connection->id,
            'feed_profile_id' => $feedProfile->id,
            'source_category_id' => $sourceCategory->id,
            'kasta_category_id' => $kastaCategory->id,
            'mapping_strategy' => CategoryMapping::STRATEGY_MANUAL,
            'is_active' => true,
        ]);

        return compact('shop', 'admin', 'connection', 'feedProfile', 'sourceCategory', 'product', 'variant', 'kastaCategory');
    }
}
