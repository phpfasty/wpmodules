<?php

/**
 * SitePulse site keys: `sk_live_…` (secret, server-side only) and `pk_live_…` (public, for the tracker).
 * diagd issues `<kind>_live_` + 36 hex characters; the check accepts any `_live_`/`_test_` alphanumeric tail.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class ApiKey
{
    public const SECRET = 'sk';
    public const PUBLIC = 'pk';

    public static function isValid(string $kind, string $key): bool
    {
        return in_array($kind, [self::SECRET, self::PUBLIC], true)
            && 1 === preg_match('/^'.$kind.'_(live|test)_[A-Za-z0-9]{16,128}$/', $key);
    }

    /** "sk_live_…ab12": never the full secret; empty for an empty key. */
    public static function mask(string $key): string
    {
        if ('' === $key) {
            return '';
        }
        $prefix = 1 === preg_match('/^([a-z]{2}_(live|test)_)/', $key, $m) ? $m[1] : '';

        return $prefix.'…'.(strlen($key) >= 12 ? substr($key, -4) : '');
    }

    /**
     * New stored secret from the settings form: an empty field keeps the old one, "clear" removes it,
     * an invalid value is rejected (the old one stays).
     */
    public static function resolveSecret(string $old, string $new, bool $clear): string
    {
        if ($clear) {
            return '';
        }
        $new = trim($new);
        if ('' === $new) {
            return $old;
        }
        if (!self::isValid(self::SECRET, $new)) {
            throw new InvalidInputException('sitepulse_invalid_secret', 'The secret key must look like sk_live_…; the previous key was kept.');
        }

        return $new;
    }
}
