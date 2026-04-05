<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesMappingScope;
use App\Models\FeedProfile;
use App\Services\Mappings\Automation\MappingCoverageService;
use Illuminate\Console\Command;

class MappingCoverageCommand extends Command
{
    use ParsesMappingScope;

    protected $signature = 'mapping:coverage {feedProfileId} {--scope=}';

    protected $description = 'Show mapping coverage summary for a feed profile.';

    public function handle(MappingCoverageService $service): int
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $this->argument('feedProfileId'));
        $coverage = $service->summarize($feedProfile, $this->parseMappingScope($this->option('scope')));

        $this->table(
            ['Metric', 'Value'],
            [
                ['Category coverage %', $coverage['summary']['category_coverage_pct']],
                ['Attribute mappings', $coverage['summary']['attribute_mapping_count']],
                ['Value mappings', $coverage['summary']['value_mapping_count']],
                ['Unresolved mapping items', $coverage['summary']['unresolved_mapping_items']],
                ['Ready gain: categories', $coverage['estimated_ready_gain']['category']],
                ['Ready gain: attributes', $coverage['estimated_ready_gain']['attribute']],
                ['Ready gain: values', $coverage['estimated_ready_gain']['value']],
            ]
        );

        return self::SUCCESS;
    }
}
