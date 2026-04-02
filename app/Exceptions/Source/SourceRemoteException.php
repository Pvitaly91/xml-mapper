<?php

namespace App\Exceptions\Source;

use App\Models\SourceConnection;

class SourceRemoteException extends SourceDriverException
{
    public function __construct(string $message = 'Remote source returned an unexpected response.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, SourceConnection::CHECK_STATUS_REMOTE_ERROR, $code, $previous);
    }
}
