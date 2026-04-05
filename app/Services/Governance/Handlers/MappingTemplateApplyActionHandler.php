<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Mappings\Automation\MappingTemplateLibraryService;

class MappingTemplateApplyActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly MappingTemplateLibraryService $templateService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $payload['feed_profile_id']);
        $summary = $this->templateService->applyPayload(
            $feedProfile,
            (array) ($payload['template_payload'] ?? []),
            (string) ($payload['collision_strategy'] ?? 'skip_existing'),
            $actor
        );

        return [
            'feed_profile_id' => $feedProfile->id,
            'summary' => $summary,
        ];
    }
}
