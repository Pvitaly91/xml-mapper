<?php

namespace App\Exceptions\Source;

use RuntimeException;
use Throwable;

class SourceDriverException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $status,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
