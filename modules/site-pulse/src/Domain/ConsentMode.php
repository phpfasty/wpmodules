<?php

/**
 * When the tracker may start: immediately (`off`) or only after an analytics consent signal (`require`).
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

enum ConsentMode: string
{
    case Off = 'off';
    case WaitForConsent = 'require';

    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::WaitForConsent) : self::WaitForConsent;
    }
}
