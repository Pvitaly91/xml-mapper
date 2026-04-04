<?php

namespace App\Data\Auth;

class StepUpResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    public function allowed(): bool
    {
        return $this->status === 'allowed';
    }
}
