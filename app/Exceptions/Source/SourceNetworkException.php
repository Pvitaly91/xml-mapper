<?php

namespace App\Exceptions\Source;

use App\Models\SourceConnection;

class SourceNetworkException extends SourceDriverException
{
    public function __construct(string $message = 'Source network request failed.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, SourceConnection::CHECK_STATUS_NETWORK_ERROR, $code, $previous);
    }
}
