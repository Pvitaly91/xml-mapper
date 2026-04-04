<?php

namespace App\Services\Ops;

use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Log;

class OpsStructuredLogService
{
    public function __construct(
        private readonly CorrelationContext $correlationContext,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $workflow, string $message, array $context = []): void
    {
        $this->write('info', $workflow, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $workflow, string $message, array $context = []): void
    {
        $this->write('warning', $workflow, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $workflow, string $message, array $context = []): void
    {
        $this->write('error', $workflow, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $workflow, string $message, array $context): void
    {
        Log::log($level, $message, $this->redactor->redactArray(array_merge($context, [
            'workflow' => $workflow,
            'correlation_id' => $this->correlationContext->ensure(),
        ])));
    }
}
