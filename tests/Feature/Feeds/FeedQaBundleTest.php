<?php

namespace Tests\Feature\Feeds;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Services\Feeds\FeedQaBundleService;
use App\Services\Feeds\FeedReleaseNotesService;
use App\Services\Feeds\FeedReleaseService;
use App\Services\Feeds\FeedSignoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdminContext;
use Tests\TestCase;
use ZipArchive;

class FeedQaBundleTest extends TestCase
{
    use CreatesAdminContext;
    use RefreshDatabase;

    public function test_bundle_generated_with_expected_files_and_summary(): void
    {
        Storage::fake(config('feed_mediator.storage_disk'));

        ['admin' => $admin, 'feedProfile' => $feedProfile] = $this->seedBuildableCatalog();
        $generation = app(FeedBuildServiceInterface::class)->build($feedProfile);
        $releaseService = app(FeedReleaseService::class);
        $releaseService->markCandidate($generation, $admin, 'Ready for client review');
        app(FeedSignoffService::class)->record($generation->fresh(), 'internal_approved', $admin, 'Ops QA', 'Looks ready');
        app(FeedReleaseNotesService::class)->add($generation->fresh(), 'Client-visible note for QA bundle.', 'external', true, $admin);

        $bundle = app(FeedQaBundleService::class)->generate($generation->fresh(), $admin, 'Pilot QA package');

        $this->assertFileExists($bundle['absolute_path']);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($bundle['absolute_path']) === true);
        $this->assertNotFalse($zip->locateName('candidate.xml'));
        $this->assertNotFalse($zip->locateName('summary.json'));
        $this->assertNotFalse($zip->locateName('invalid-items.csv'));
        $this->assertNotFalse($zip->locateName('generation-diff.json'));
        $this->assertNotFalse($zip->locateName('readiness.json'));
        $this->assertNotFalse($zip->locateName('smoke-check-summary.json'));
        $this->assertNotFalse($zip->locateName('release-notes.txt'));

        $summary = json_decode($zip->getFromName('summary.json'), true, 512, JSON_THROW_ON_ERROR);
        $releaseNotes = (string) $zip->getFromName('release-notes.txt');
        $zip->close();

        $this->assertSame($generation->id, $summary['generation']['id']);
        $this->assertSame($feedProfile->id, $summary['feed_profile']['id']);
        $this->assertStringContainsString('Client-visible note for QA bundle.', $releaseNotes);
    }
}
