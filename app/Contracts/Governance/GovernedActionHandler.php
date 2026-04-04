<?php

namespace App\Contracts\Governance;

use App\Models\ApprovalRequest;
use App\Models\User;

interface GovernedActionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array;
}
