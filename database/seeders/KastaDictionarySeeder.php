<?php

namespace Database\Seeders;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use Illuminate\Database\Seeder;

class KastaDictionarySeeder extends Seeder
{
    public function run(): void
    {
        app(KastaDictionaryImportServiceInterface::class)->import();
    }
}
