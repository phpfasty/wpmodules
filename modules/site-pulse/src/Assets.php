<?php

/**
 * Packaged assets read from the module folder and printed inline (modules may live outside the web root).
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

final class Assets
{
    private static string $dir = '';

    public static function configure(string $dir): void
    {
        self::$dir = $dir;
    }

    public static function read(string $name): string
    {
        if ('' === self::$dir || 1 !== preg_match('/^[a-z-]+\.(js|css)$/', $name)) {
            return '';
        }
        $file = self::$dir.'/'.$name;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- own packaged asset.

        return is_file($file) ? (string) file_get_contents($file) : '';
    }
}
