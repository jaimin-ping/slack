<?php

namespace App\Ai\Exceptions;

use RuntimeException;
use Throwable;

/** The provider could not answer. Retryable failures (rate limits, overload, timeouts) may be tried again later. */
class ProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
