<?php

namespace App\Jobs\Middleware;

use App\Services\Ops\CorrelationContext;
use Closure;

class SetCorrelationContext
{
    public function __construct(
        private readonly ?string $correlationId,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        app(CorrelationContext::class)->activate($this->correlationId);

        return $next($job);
    }
}
