<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Package;

// phpcs:disable WordPress.WP.AlternativeFunctions -- CMS-agnostic core: works on its own quarantine/modules folders with plain PHP I/O (no WordPress here).
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Minimal filesystem helpers (no host API): recursive listing and removal that never follow symlinks.
 */
final class Fs
{
    /**
     * Relative paths (with /) of regular files under $dir, sorted.
     *
     * @return list<string>
     */
    public static function files(string $dir): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $prefix = strlen(rtrim($dir, '/\\')) + 1;
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink()) {
                $out[] = str_replace('\\', '/', substr($file->getPathname(), $prefix));
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($path);
    }
}
