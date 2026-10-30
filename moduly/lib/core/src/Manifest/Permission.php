<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Manifest;

/**
 * Permissions a module may declare (TZ §5.2). The host adapter enforces what it can through its own APIs;
 * the rest is covered by the static scan and the publisher signature.
 */
final class Permission
{
    public const FIXED = [
        'http:none',
        'db:options',
        'db:postmeta',
        'db:custom-table',
        'cpt:register',
        'rest:public',
        'rest:admin',
        'cookie:write',
        'cron',
        'files:private',
        'mail:send',
        'users:read',
        'capabilities:add',
    ];

    public static function isValid(string $permission): bool
    {
        if (in_array($permission, self::FIXED, true)) {
            return true;
        }
        // http:outbound:<host>
        return preg_match('/^http:outbound:[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $permission) === 1;
    }
}
