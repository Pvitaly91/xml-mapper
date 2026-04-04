<?php

namespace App\Jobs;

use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Jobs\Concerns\UsesCorrelationContext;
use App\Models\SourceImport;
use App\Services\Ops\CorrelationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NormalizeProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesCorrelationContext;

    public int $timeout = 1800;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly int $sourceImportId,
        public ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('feed_mediator.queues.normalization'));
        $this->correlationId ??= app(CorrelationContext::class)->id();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SourceSyncWorkflowServiceInterface $workflow): void
    {
        $workflow->normalize(SourceImport::findOrFail($this->sourceImportId));
    }
}
