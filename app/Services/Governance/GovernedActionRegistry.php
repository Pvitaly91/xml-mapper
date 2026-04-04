<?php

namespace App\Services\Governance;

use App\Contracts\Governance\GovernedActionHandler;
use RuntimeException;

class GovernedActionRegistry
{
    /**
     * @param  array<string, GovernedActionHandler>  $handlers
     */
    public function __construct(
        private readonly array $handlers,
    ) {}

    public function handler(string $action): GovernedActionHandler
    {
        if (! array_key_exists($action, $this->handlers)) {
            throw new RuntimeException('No governed action handler is registered for ['.$action.'].');
        }

        return $this->handlers[$action];
    }
}
