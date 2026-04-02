<?php

namespace App\Console\Commands;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use Illuminate\Console\Command;

class KastaReimportDictionariesCommand extends Command
{
    protected $signature = 'kasta:reimport-dictionaries {--path= : Directory with categories.json, attributes.json, attribute_values.json and size_grids.json}';

    protected $description = 'Reimport Kasta reference dictionaries from JSON files using idempotent upserts.';

    public function handle(KastaDictionaryImportServiceInterface $service): int
    {
        $summary = $service->importBundle($this->option('path') ?: null);

        $this->info(sprintf(
            'Reimport completed: %d categories, %d attributes, %d values, %d size grids.',
            $summary['categories'],
            $summary['attributes'],
            $summary['attribute_values'],
            $summary['size_grids']
        ));

        return self::SUCCESS;
    }
}
