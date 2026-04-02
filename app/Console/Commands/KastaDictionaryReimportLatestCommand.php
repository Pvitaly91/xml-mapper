<?php

namespace App\Console\Commands;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Jobs\ImportDictionaryJob;
use Illuminate\Console\Command;

class KastaDictionaryReimportLatestCommand extends Command
{
    protected $signature = 'kasta:dictionary:reimport-latest
        {type : Dictionary type}
        {--dry-run : Parse and diff without persisting}
        {--deactivate-missing : Mark dictionary entries missing from the file as inactive}
        {--queue : Dispatch the reimport to the dictionaries queue}';

    protected $description = 'Reimport the latest stored file for the given Kasta dictionary type.';

    public function handle(KastaDictionaryImportServiceInterface $service): int
    {
        if ($this->option('queue')) {
            ImportDictionaryJob::dispatch(
                (string) $this->argument('type'),
                null,
                null,
                (bool) $this->option('dry-run'),
                (bool) $this->option('deactivate-missing'),
                true,
                null,
                null,
                true,
            );

            $this->info('Dictionary reimport job queued.');

            return self::SUCCESS;
        }

        $import = $service->reimportLatest(
            (string) $this->argument('type'),
            (bool) $this->option('dry-run'),
            (bool) $this->option('deactivate-missing'),
        );

        $this->info(sprintf(
            'Reimport #%d completed with status [%s]. Created: %d, updated: %d, skipped: %d, deactivated: %d.',
            $import->id,
            $import->status,
            $import->created_count,
            $import->updated_count,
            $import->skipped_count,
            $import->deactivated_count,
        ));

        return self::SUCCESS;
    }
}
