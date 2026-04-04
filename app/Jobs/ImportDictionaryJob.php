<?php

namespace App\Jobs;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Data\Dictionaries\DictionaryImportOptions;
use App\Jobs\Concerns\UsesCorrelationContext;
use App\Services\Ops\CorrelationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportDictionaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesCorrelationContext;

    public int $timeout = 1800;

    public int $tries = 2;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly string $type,
        public readonly ?string $filePath = null,
        public readonly ?string $format = null,
        public readonly bool $dryRun = false,
        public readonly bool $deactivateMissing = false,
        public readonly bool $allowDuplicateChecksum = false,
        public readonly ?int $initiatedByUserId = null,
        public readonly ?string $originalFilename = null,
        public readonly bool $reimportLatest = false,
        public ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('feed_mediator.queues.dictionaries'));
        $this->correlationId ??= app(CorrelationContext::class)->id();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(KastaDictionaryImportServiceInterface $service): void
    {
        if ($this->reimportLatest) {
            $service->reimportLatest($this->type, $this->dryRun, $this->deactivateMissing, $this->initiatedByUserId);

            return;
        }

        $service->import(new DictionaryImportOptions(
            type: $this->type,
            filePath: $this->filePath,
            format: $this->format,
            dryRun: $this->dryRun,
            deactivateMissing: $this->deactivateMissing,
            allowDuplicateChecksum: $this->allowDuplicateChecksum,
            initiatedByUserId: $this->initiatedByUserId,
            originalFilename: $this->originalFilename,
        ));
    }
}
