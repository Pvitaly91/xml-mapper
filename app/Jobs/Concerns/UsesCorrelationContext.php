<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\SetCorrelationContext;

trait UsesCorrelationContext
{
    public function middleware(): array
    {
        return [
            new SetCorrelationContext($this->correlationId ?? null),
        ];
    }
}
