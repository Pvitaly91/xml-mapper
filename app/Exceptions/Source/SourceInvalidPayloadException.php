<?php

namespace App\Exceptions\Source;

use App\Models\SourceConnection;

class SourceInvalidPayloadException extends SourceDriverException
{
    public function __construct(string $message = 'Source payload is invalid.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, SourceConnection::CHECK_STATUS_INVALID_PAYLOAD, $code, $previous);
    }
}
