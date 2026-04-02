<?php

namespace App\Console\Commands;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Jobs\ImportDictionaryJob;
use Illuminate\Console\Command;

class KastaDictionaryImportCommand extends Command
{
    protected $signature = 'kasta:dictionary:import
        {type : Dictionary type}
        {--file= : Path to the source file}
        {--format= : Source format (json or csv)}
        {--dry-run : Parse and diff without persisting}
        {--deactivate-missing : Mark dictionary entries missing from the file as inactive}
        {--queue : Dispatch the import to the dictionaries queue}';

    protected $description = 'Import a Kasta dictionary file in JSON or CSV format.';

    public function handle(KastaDictionaryImportServiceInterface $service): int
    {
        if ($this->option('queue')) {
            ImportDictionaryJob::dispatch(
                (string) $this->argument('type'),
                $this->option('file') ?: null,
                $this->option('format') ?: null,
                (bool) $this->option('dry-run'),
                (bool) $this->option('deactivate-missing'),
            );

            $this->info('Dictionary import job queued.');

            return self::SUCCESS;
        }

        $import = $service->import(new DictionaryImportOptions(
            type: (string) $this->argument('type'),
            filePath: $this->option('file') ?: null,
            format: $this->option('format') ?: null,
            dryRun: (bool) $this->option('dry-run'),
            deactivateMissing: (bool) $this->option('deactivate-missing'),
        ));

        $this->table(['Field', 'Value'], [
            ['Import ID', $import->id],
            ['Status', $import->status],
            ['Rows total', $import->rows_total],
            ['Created', $import->created_count],
            ['Updated', $import->updated_count],
            ['Skipped', $import->skipped_count],
            ['Deactivated', $import->deactivated_count],
            ['Dry run', $import->dry_run ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
