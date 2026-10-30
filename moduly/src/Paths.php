<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

/**
 * Filesystem layout: modules live outside the plugin (they survive plugin updates) in wp-content/moduly/.
 */
final class Paths
{
    public static function root(): string
    {
        $root = defined('MODULY_DIR') && is_string(constant('MODULY_DIR')) ? (string) constant('MODULY_DIR') : WP_CONTENT_DIR . '/moduly';
        return rtrim($root, '/\\');
    }

    public static function modules(): string
    {
        return self::root() . '/modules';
    }

    public static function quarantine(): string
    {
        return self::root() . '/quarantine';
    }

    /** Create the folders with deny rules (Apache) and silent index files. */
    public static function ensure(): bool
    {
        foreach ([self::root(), self::modules(), self::quarantine()] as $dir) {
            if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                return false;
            }
            if (!is_file($dir . '/index.php')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file in our own folder.
                file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            }
        }
        if (!is_file(self::quarantine() . '/.htaccess')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- deny web access to quarantine.
            file_put_contents(self::quarantine() . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return true;
    }
}
