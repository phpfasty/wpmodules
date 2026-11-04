<?php

/**
 * Cookie consent domain (no WordPress): the stored choice and what it allows.
 */

declare(strict_types=1);

namespace PhpFasty\Module\CookieConsent\Domain;

final class Choice
{
    public const ALL = 'all';
    public const NECESSARY = 'necessary';

    public static function allowsAnalytics(string $value): bool
    {
        return self::ALL === $value;
    }

    public static function valid(string $value): bool
    {
        return self::ALL === $value || self::NECESSARY === $value;
    }
}
