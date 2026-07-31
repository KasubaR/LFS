<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class CodeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
