<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CorrelationContext
{
    private ?string $correlationId = null;

    public function id(): ?string
    {
        return $this->correlationId;
    }

    public function ensure(?string $preferred = null): string
    {
        if ($this->correlationId === null) {
            $this->activate($preferred ?: (string) Str::uuid());
        }

        return (string) $this->correlationId;
    }

    public function activate(?string $correlationId): string
    {
        $this->correlationId = filled($correlationId) ? (string) $correlationId : (string) Str::uuid();
        Log::shareContext([
            'correlation_id' => $this->correlationId,
        ]);

        return $this->correlationId;
    }
}
