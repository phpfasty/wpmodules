<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Package;

use PhpFasty\Moduly\Exception\PackageException;
use ZipArchive;

/**
 * Publisher-side: reproducible module zip (sorted entries, fixed mtime, fixed permissions).
 */
final class Packer
{
    public static function pack(string $moduleDir, string $zipPath, int $mtime = 315532800): void
    {
        $id = basename($moduleDir);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PackageException('fs', 'Cannot create zip');
        }
        foreach (Fs::files($moduleDir) as $rel) {
            SafeZipExtractor::assertAllowedFile($id . '/' . $rel);
            $name = $id . '/' . $rel;
            $zip->addFile($moduleDir . '/' . $rel, $name);
            $zip->setMtimeName($name, $mtime);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }
        $zip->close();
    }
}
