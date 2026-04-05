<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Mappings\Automation\MappingFeedbackRecommendationService;
use Illuminate\Console\Command;

class MappingFeedbackRecommendationsCommand extends Command
{
    protected $signature = 'mapping:feedback-recommendations {feedProfileId}';

    protected $description = 'Show deterministic feedback-driven mapping recommendations.';

    public function handle(MappingFeedbackRecommendationService $service): int
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $this->argument('feedProfileId'));
        $recommendations = $service->recommend($feedProfile);

        $this->info(sprintf('Recommendations: %d', count($recommendations)));
        $this->table(
            ['Type', 'Subject', 'Impact', 'Rationale'],
            collect($recommendations)->take(20)->map(fn (array $item) => [
                $item['recommendation_type'],
                $item['subject'],
                $item['impact_count'],
                $item['rationale'],
            ])->all()
        );

        return self::SUCCESS;
    }
}
