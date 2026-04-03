<?php

namespace App\Console\Commands;

use App\Models\FeedGeneration;
use App\Models\FeedProfile;
use App\Services\Feeds\FeedbackImportService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class FeedbackImportCommand extends Command
{
    protected $signature = 'feedback:import
        {type : csv or json}
        {--file= : Absolute path to feedback file}
        {--feed-profile= : Feed profile ID}
        {--generation= : Optional feed generation ID}
        {--dry-run : Preview import without persisting data}';

    protected $description = 'Import manual merchant acceptance/rejection feedback from CSV or JSON.';

    public function handle(FeedbackImportService $service): int
    {
        $path = $this->option('file') !== null ? (string) $this->option('file') : null;
        $feedProfileId = $this->option('feed-profile') !== null ? (int) $this->option('feed-profile') : null;

        if ($path === null || $feedProfileId === null) {
            throw new RuntimeException('Provide both --file and --feed-profile.');
        }

        $feedProfile = FeedProfile::findOrFail($feedProfileId);
        $generation = $this->option('generation') !== null
            ? FeedGeneration::query()->where('feed_profile_id', $feedProfile->id)->findOrFail((int) $this->option('generation'))
            : $feedProfile->publishedGeneration;
        $uploadedFile = new UploadedFile($path, basename($path), null, null, true);
        $result = $service->importUploadedFile(
            $feedProfile,
            (string) $this->argument('type'),
            $uploadedFile,
            (bool) $this->option('dry-run'),
            null,
            $generation
        );

        $this->line(json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
