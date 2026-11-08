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

    /**
     * True when the request may have reached Lenco and been charged: network
     * errors and timeouts (status 0), 5xx, and anything retryable. A 4xx means
     * Lenco refused it, so nothing was charged.
     */
    public function outcomeIsUnknown(): bool
    {
        return $this->retryable || $this->httpStatus === 0 || $this->httpStatus >= 500;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }
}
