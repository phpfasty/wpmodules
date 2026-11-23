<?php

/**
 * Invalid settings or request input (HTTP 400).
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class InvalidInputException extends SitePulseException
{
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($errorCode, $message, 400);
    }
}
