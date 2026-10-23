<?php

namespace Waterline\Support;

use RuntimeException;

final class RequestTimeoutException extends RuntimeException
{
    public string $reason;
    public int $status;

    public function __construct(string $message, string $reason = 'waterline_request_timeout_invalid', int $status = 500)
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->status = $status;
    }
}
