<?php

namespace App\Exceptions\Source;

use App\Models\SourceConnection;

class SourceRateLimitException extends SourceDriverException
{
    public function __construct(string $message = 'Source rate limit reached.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, SourceConnection::CHECK_STATUS_RATE_LIMITED, $code, $previous);
    }
}
