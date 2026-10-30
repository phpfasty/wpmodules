<?php

declare(strict_types=1);

namespace PhpFasty\Moduly;

/**
 * Core package metadata. CMS-agnostic: no host (WordPress etc.) calls allowed in src/.
 */
final class Moduly
{
    public const VERSION = '0.1.0';

    public static function version(): string
    {
        return self::VERSION;
    }
}
