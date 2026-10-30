<?php

/**
 * Private storage: product download files live in uploads/moduly-private/ (deny-all .htaccess + index.php).
 * A file attached to a private `file` field is moved there when the product is saved; the attachment keeps
 * pointing at it (`_wp_attached_file`), so nothing is lost and no public URL serves it.
 *
 * @package ModulY\Downloads
 */

declare(strict_types=1);

namespace PhpFasty\Module\Downloads;

final class Storage
{
    public const DIR = 'moduly-private';

    public static function dir(): string
    {
        $up = wp_upload_dir(null, false);
        return rtrim((string) $up['basedir'], '/') . '/' . self::DIR;
    }

    /** Create the private directory with deny rules (idempotent). */
    public static function ensure(): string
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $files = [
            '.htaccess' => "Require all denied\nDeny from all\n",
            'index.php' => "<?php\n// Silence is golden.\n",
            'web.config' => '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        ];
        foreach ($files as $name => $body) {
            if (!file_exists($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $body);
            }
        }
        return $dir;
    }

    /** Is the path inside the private directory (no traversal, real file)? */
    public static function isPrivate(string $path): bool
    {
        $real = realpath($path);
        $dir = realpath(self::dir());
        return $real !== false && $dir !== false && str_starts_with($real, $dir . DIRECTORY_SEPARATOR) && is_file($real);
    }

    /** Move an attachment's file into the private directory; returns the absolute path or '' on failure. */
    public static function protect(int $attachmentId): string
    {
        $path = (string) get_attached_file($attachmentId);
        if ($path === '' || !is_file($path)) {
            return '';
        }
        if (self::isPrivate($path)) {
            return $path;
        }
        $dir = self::ensure();
        $name = wp_unique_filename($dir, $attachmentId . '-' . bin2hex(random_bytes(8)) . '-' . basename($path));
        $target = $dir . '/' . $name;
        if (!rename($path, $target)) {
            return '';
        }
        update_attached_file($attachmentId, $target);
        return $target;
    }
}
