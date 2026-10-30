<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Package;

use PhpFasty\Moduly\Exception\PackageException;
use ZipArchive;

/**
 * Unpacks a module zip without ZipArchive::extractTo(): every entry is checked (zip-slip, absolute paths,
 * backslashes, drive letters, NUL, symlinks, extension whitelist, size / ratio / count limits) and streamed
 * into a fresh directory under the quarantine root. Nothing in the archive is ever executed here.
 */
final class SafeZipExtractor
{
    private const SYMLINK_MODE = 0120000;
    private const FILE_TYPE_MASK = 0170000;

    public function __construct(private readonly Limits $limits = new Limits())
    {
    }

    /**
     * @return string absolute path of the new quarantine directory holding the unpacked files
     */
    public function extract(string $zipPath, string $quarantineRoot): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PackageException('zip_unavailable', 'The PHP zip extension is required');
        }
        $size = @filesize($zipPath);
        if ($size === false || $size <= 0) {
            throw new PackageException('not_readable', 'Package file is not readable');
        }
        if ($size > $this->limits->maxZipBytes) {
            throw new PackageException('too_large', 'Package is larger than the allowed size');
        }
        $magic = (string) @file_get_contents($zipPath, false, null, 0, 4);
        if ($magic !== "PK\x03\x04") {
            throw new PackageException('not_zip', 'Package is not a zip archive');
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo !== false ? (string) finfo_file($finfo, $zipPath) : '';
            if ($mime !== '' && !in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
                throw new PackageException('not_zip', 'Package is not a zip archive');
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new PackageException('not_zip', 'Package cannot be opened as zip');
        }
        try {
            return $this->unpack($zip, $size, $quarantineRoot);
        } finally {
            $zip->close();
        }
    }

    private function unpack(ZipArchive $zip, int $zipSize, string $quarantineRoot): string
    {
        $count = $zip->numFiles;
        if ($count < 1 || $count > $this->limits->maxEntries) {
            throw new PackageException('too_many_entries', 'Package has too many (or no) entries');
        }
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new PackageException('corrupt', 'Package entry cannot be read');
            }
            $name = $stat['name'];
            $path = self::normalize($name, $this->limits->maxPathLength);
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && is_int($attr)) {
                if ((($attr >> 16) & self::FILE_TYPE_MASK) === self::SYMLINK_MODE) {
                    throw new PackageException('symlink', 'Package contains a symbolic link');
                }
            }
            $isDir = str_ends_with($name, '/');
            if (!$isDir) {
                self::assertAllowedFile($path);
                $total += $stat['size'];
                if ($total > $this->limits->maxUnpackedBytes) {
                    throw new PackageException('too_large_unpacked', 'Unpacked package is larger than allowed');
                }
            }
            $entries[] = ['index' => $i, 'path' => $path, 'dir' => $isDir, 'size' => $stat['size']];
        }
        if ($total > $zipSize * $this->limits->maxRatio) {
            throw new PackageException('zip_bomb', 'Package compression ratio is suspicious');
        }

        if (!is_dir($quarantineRoot) && !@mkdir($quarantineRoot, 0755, true) && !is_dir($quarantineRoot)) {
            throw new PackageException('fs', 'Quarantine directory cannot be created');
        }
        $root = realpath($quarantineRoot);
        if ($root === false) {
            throw new PackageException('fs', 'Quarantine directory is not available');
        }
        $target = $root . DIRECTORY_SEPARATOR . 'q-' . bin2hex(random_bytes(8));
        if (!@mkdir($target, 0755)) {
            throw new PackageException('fs', 'Quarantine directory cannot be created');
        }

        try {
            foreach ($entries as $e) {
                $dest = $target . DIRECTORY_SEPARATOR . $e['path'];
                if ($e['dir']) {
                    self::mkdirInside($dest, $target);
                    continue;
                }
                self::mkdirInside(dirname($dest), $target);
                $in = $zip->getStream($zip->getNameIndex($e['index']) ?: '');
                if ($in === false) {
                    throw new PackageException('corrupt', 'Package entry cannot be read');
                }
                $out = @fopen($dest, 'xb');
                if ($out === false) {
                    fclose($in);
                    throw new PackageException('duplicate_entry', 'Package contains a duplicate or unwritable entry');
                }
                $written = 0;
                while (!feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        break;
                    }
                    $written += strlen($chunk);
                    if ($written > $e['size']) {
                        fclose($in);
                        fclose($out);
                        throw new PackageException('size_mismatch', 'Package entry is larger than declared');
                    }
                    fwrite($out, $chunk);
                }
                fclose($in);
                fclose($out);
                $real = realpath($dest);
                if ($real === false || !str_starts_with($real, $target . DIRECTORY_SEPARATOR)) {
                    throw new PackageException('path_escape', 'Package entry escapes the quarantine');
                }
            }
        } catch (PackageException $e) {
            Fs::remove($target);
            throw $e;
        }
        return $target;
    }

    /**
     * Normalized relative path, or an exception for anything unsafe.
     */
    public static function normalize(string $name, int $maxLength = 200): string
    {
        if (
            $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_contains($name, ':')
            || str_starts_with($name, '/')
            || strlen($name) > $maxLength
        ) {
            throw new PackageException('bad_path', 'Package contains an unsafe path');
        }
        $parts = [];
        foreach (explode('/', rtrim($name, '/')) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                throw new PackageException('bad_path', 'Package contains an unsafe path');
            }
            if (preg_match('/^[A-Za-z0-9._@\-]+$/', $seg) !== 1) {
                throw new PackageException('bad_path', 'Package contains an unsupported file name');
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    public static function assertAllowedFile(string $path): void
    {
        $base = basename($path);
        if (str_starts_with($base, '.')) {
            throw new PackageException('hidden_file', 'Package contains a hidden file');
        }
        if (in_array($base, Limits::BARE_FILES, true)) {
            return;
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, Limits::EXTENSIONS, true)) {
            throw new PackageException('file_type', 'Package contains a file type that is not allowed');
        }
        // Executable PHP only under src/ (or the module root bootstrap is not allowed at all).
        if ($ext === 'php') {
            $segments = explode('/', $path);
            if (count($segments) < 3 || $segments[1] !== 'src') {
                throw new PackageException('php_location', 'PHP files are only allowed under <module>/src/');
            }
        }
    }

    private static function mkdirInside(string $dir, string $root): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new PackageException('fs', 'Directory cannot be created');
        }
        $real = realpath($dir);
        if ($real === false || ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR))) {
            throw new PackageException('path_escape', 'Package entry escapes the quarantine');
        }
    }
}
