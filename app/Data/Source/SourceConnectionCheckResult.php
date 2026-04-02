<?php

namespace App\Data\Source;

readonly class SourceConnectionCheckResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $status,
        public string $message,
        public array $meta = [],
    ) {
    }
}
