<?php

namespace App\Exceptions;

use RuntimeException;

class PayPalException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 503)
    {
        // Store a short technical code only, never upstream bodies or credentials.
        parent::__construct($reason);
    }
}
