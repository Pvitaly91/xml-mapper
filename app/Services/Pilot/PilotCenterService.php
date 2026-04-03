<?php

namespace App\Services\Pilot;

use App\Models\PilotRun;
use App\Models\Shop;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PilotCenterService
{
    public function __construct(
        private readonly PilotExecutionService $executionService,
        private readonly PilotReadinessScoreService $readinessScoreService,
    ) {}

    public function list(Shop $shop, int $perPage = 20): LengthAwarePaginator
    {
        return PilotRun::query()
            ->with(['feedProfile', 'owner', 'initiatedBy'])
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->paginate($perPage)
            ->through(function (PilotRun $run): PilotRun {
                if (! data_get($run->summary, 'readiness')) {
                    $run->summary = array_replace_recursive($run->summary ?? [], [
                        'readiness' => $this->readinessScoreService->score($run->feedProfile, $run),
                    ]);
                }

                return $run;
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(PilotRun $pilotRun): array
    {
        $pilotRun = $this->executionService->refreshOperationalState($pilotRun->fresh([
            'feedProfile.shop',
            'feedProfile.sourceConnection.latestImport',
            'feedProfile.latestGeneration',
            'feedProfile.publishedGeneration',
            'feedProfile.currentHypercareWindow',
            'sourceSnapshot',
            'candidateGeneration',
            'publishedGeneration',
            'initiatedBy',
            'owner',
            'events.user',
        ]));

        return [
            'run' => $pilotRun,
            'history' => $pilotRun->events()->with('user')->orderByDesc('occurred_at')->paginate(25)->withQueryString(),
            'readiness' => data_get($pilotRun->summary, 'readiness', $this->readinessScoreService->score($pilotRun->feedProfile, $pilotRun)),
            'blocker' => data_get($pilotRun->summary, 'blocker'),
            'next_step' => [
                'step' => data_get($pilotRun->summary, 'execution.next_step'),
                'label' => data_get($pilotRun->summary, 'execution.next_step_label'),
            ],
        ];
    }
}
