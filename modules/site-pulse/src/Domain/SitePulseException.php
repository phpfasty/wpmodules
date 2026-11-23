<?php

/**
 * Base exception of the SitePulse module: a stable error code and the HTTP status the plugin answers with.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

class SitePulseException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 500,
        private readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Seconds from the upstream Retry-After header (429), 0 when not given. */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
