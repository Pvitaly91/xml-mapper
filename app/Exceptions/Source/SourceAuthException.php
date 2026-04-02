<?php

namespace App\Exceptions\Source;

use App\Models\SourceConnection;

class SourceAuthException extends SourceDriverException
{
    public function __construct(string $message = 'Authentication with the source driver failed.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, SourceConnection::CHECK_STATUS_AUTH_FAILED, $code, $previous);
    }
}
