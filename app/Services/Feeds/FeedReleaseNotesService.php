<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedReleaseEvent;
use App\Models\User;
use Illuminate\Support\Collection;

class FeedReleaseNotesService
{
    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
    ) {
    }

    public function add(
        FeedGeneration $generation,
        string $body,
        string $type = 'internal',
        bool $important = false,
        ?User $user = null
    ): FeedReleaseEvent {
        return $this->auditService->record(
            $generation->feedProfile,
            $generation,
            'note_added',
            $user,
            $body,
            [
                'note_type' => $type,
                'important' => $important,
                'body' => $body,
            ]
        );
    }

    /**
     * @return Collection<int, FeedReleaseEvent>
     */
    public function notes(FeedGeneration $generation): Collection
    {
        return $generation->releaseEvents()
            ->where('action', 'note_added')
            ->with('user')
            ->latest('occurred_at')
            ->get();
    }

    /**
     * @return Collection<int, FeedReleaseEvent>
     */
    public function importantNotes(FeedGeneration $generation): Collection
    {
        return $this->notes($generation)
            ->filter(static fn (FeedReleaseEvent $event) => (bool) ($event->meta['important'] ?? false))
            ->values();
    }
}
