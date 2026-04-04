<?php

namespace App\Services\Governance\Handlers;

use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\FeedProfile;
use App\Models\User;
use App\Services\Ops\SilenceWindowService;

class CriticalSilenceWindowActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly SilenceWindowService $silenceWindowService,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $payload['feed_profile_id']);
        $window = $this->silenceWindowService->start(
            $feedProfile,
            $this->silenceWindowService->parse($payload['from'] ?? null, $feedProfile),
            $this->silenceWindowService->parse($payload['to'] ?? null, $feedProfile),
            (string) ($payload['severity'] ?? 'critical'),
            (string) ($payload['reason'] ?? ''),
            $actor
        );

        return [
            'feed_profile_id' => $feedProfile->id,
            'silence_window_id' => $window->id,
        ];
    }
}
