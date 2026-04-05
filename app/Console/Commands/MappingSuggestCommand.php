<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesMappingScope;
use App\Models\FeedProfile;
use App\Services\Mappings\Automation\MappingSuggestionService;
use Illuminate\Console\Command;

class MappingSuggestCommand extends Command
{
    use ParsesMappingScope;

    protected $signature = 'mapping:suggest {feedProfileId} {--scope=} {--type=category}';

    protected $description = 'Generate deterministic mapping suggestions for a feed profile.';

    public function handle(MappingSuggestionService $service): int
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $this->argument('feedProfileId'));
        $type = (string) $this->option('type');
        $scope = $this->parseMappingScope($this->option('scope'));
        $suggestions = $service->suggest($feedProfile, $type, $scope);

        $this->info(sprintf('Suggestions: %d for [%s].', count($suggestions), $type));
        $this->table(
            ['Source', 'Target', 'Confidence', 'Strategy', 'Auto', 'Unlocks'],
            collect($suggestions)->take(20)->map(fn (array $suggestion) => [
                $suggestion['source']['label'],
                $suggestion['suggested_target']['label'],
                $suggestion['confidence'],
                $suggestion['match_strategy'],
                $suggestion['safe_for_auto_apply'] ? 'yes' : 'review',
                $suggestion['unlock_estimate'] ?? 0,
            ])->all()
        );

        return self::SUCCESS;
    }
}
