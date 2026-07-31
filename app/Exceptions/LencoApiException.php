<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class LencoApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $httpStatus = 0,
        private bool $retryable = false,
        private array $rawResponse = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }
}
