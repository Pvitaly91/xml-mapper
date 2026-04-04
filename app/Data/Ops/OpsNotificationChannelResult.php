<?php

namespace App\Data\Ops;

readonly class OpsNotificationChannelResult
{
    /**
     * @param  array<string, mixed>  $responseMeta
     */
    public function __construct(
        public string $status,
        public ?string $summary = null,
        public ?string $errorMessage = null,
        public array $responseMeta = [],
    ) {}
}
