<?php

namespace App\Exceptions\Source;

use App\Models\SourceConnection;

class SourceConfigurationException extends SourceDriverException
{
    public function __construct(string $message = 'Source connection is not fully configured.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, SourceConnection::CHECK_STATUS_CONFIG_ERROR, $code, $previous);
    }
}
