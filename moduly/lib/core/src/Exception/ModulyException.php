<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Exception;

use RuntimeException;

/**
 * Base exception of the core. `reason` is a stable machine code (for audit logs and API responses);
 * messages never contain absolute paths.
 */
class ModulyException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
