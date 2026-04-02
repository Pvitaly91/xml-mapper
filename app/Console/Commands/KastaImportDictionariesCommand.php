<?php

namespace App\Console\Commands;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use Illuminate\Console\Command;

class KastaImportDictionariesCommand extends Command
{
    protected $signature = 'kasta:import-dictionaries {--path= : Directory with categories.json, attributes.json, attribute_values.json and size_grids.json}';

    protected $description = 'Import Kasta reference dictionaries from JSON files.';

    public function handle(KastaDictionaryImportServiceInterface $service): int
    {
        $summary = $service->importBundle($this->option('path') ?: null);

        $this->table(['Dataset', 'Imported rows'], [
            ['categories', $summary['categories']],
            ['attributes', $summary['attributes']],
            ['attribute_values', $summary['attribute_values']],
            ['size_grids', $summary['size_grids']],
        ]);

        return self::SUCCESS;
    }
}
