<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Migration;

use PhpFasty\Moduly\Package\Fs;
use PhpFasty\Moduly\Scan\StaticScanner;

/**
 * Static check (ADR-002 §5): migration classes must not delete data.
 */
final class MigrationGuard
{
    public const FORBIDDEN = [
        'delete_post_meta', 'delete_metadata', 'delete_option', 'delete_site_option', 'delete_user_meta',
        'delete_term_meta', 'delete_transient', 'wp_delete_post', 'wp_delete_term', 'delete_post_meta_by_key',
    ];

    /** @return list<string> */
    public static function violations(string $dir): array
    {
        $scanner = new StaticScanner(self::FORBIDDEN);
        $out = [];
        foreach (Fs::files($dir) as $rel) {
            if (!str_ends_with($rel, '.php') || preg_match('#(^|/)Migration_\d{4}_[A-Za-z0-9]+\.php$#', $rel) !== 1) {
                continue;
            }
            $code = (string) file_get_contents($dir . '/' . $rel);
            foreach ($scanner->scanCode($code) as [$line, $msg]) {
                if (str_ends_with($msg, '()') && in_array(substr($msg, 0, -2), self::FORBIDDEN, true)) {
                    $out[] = "{$rel}:{$line} {$msg}";
                }
            }
            if (preg_match('/\b(DELETE\s+FROM|DROP\s+(TABLE|COLUMN)|TRUNCATE)\b/i', $code) === 1) {
                $out[] = "{$rel}: destructive SQL";
            }
            if (preg_match('/function\s+down\s*\(/i', $code) === 1) {
                $out[] = "{$rel}: down() is not allowed (forward-only)";
            }
        }
        return $out;
    }
}
