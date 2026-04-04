<?php

namespace App\Data\Governance;

use App\Models\ApprovalRequest;

class GovernedActionResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?ApprovalRequest $approvalRequest = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $execution = null,
        public readonly ?string $message = null,
    ) {}
}
