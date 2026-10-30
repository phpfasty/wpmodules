<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Package;

use PhpFasty\Moduly\Exception\ModulyException;
use PhpFasty\Moduly\Exception\PackageException;
use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Manifest\Manifest;
use PhpFasty\Moduly\Manifest\ManifestValidator;
use PhpFasty\Moduly\Scan\StaticScanner;
use PhpFasty\Moduly\Signature\Verifier;

/**
 * zip → quarantine → JSON Schema → signature + sha256 → requirements → static scan → atomic move to
 * `<modulesDir>/<id>/<version>/`. Nothing from the package is included before all checks pass; on any failure the
 * quarantine copy is removed. The installed version is not enabled here (host state decides).
 */
final class Installer
{
    public function __construct(
        private readonly string $modulesDir,
        private readonly string $quarantineDir,
        private readonly Verifier $verifier,
        private readonly DependencyResolver $resolver,
        private readonly SafeZipExtractor $extractor = new SafeZipExtractor(),
        private readonly StaticScanner $scanner = new StaticScanner(),
        private readonly ManifestValidator $validator = new ManifestValidator(),
    ) {
    }

    /**
     * @param array<string, Manifest> $installed currently installed (active versions) for dependency checks
     */
    public function install(string $zipPath, array $installed = [], bool $allowDowngrade = false): InstallResult
    {
        $quarantine = $this->extractor->extract($zipPath, $this->quarantineDir);
        try {
            $roots = array_values(array_filter(
                scandir($quarantine) ?: [],
                static fn (string $f): bool => $f !== '.' && $f !== '..'
            ));
            if (count($roots) !== 1 || !is_dir($quarantine . '/' . $roots[0])) {
                throw new PackageException('bad_layout', 'Package must contain exactly one top-level folder named after the module id');
            }
            $id = $roots[0];
            $dir = $quarantine . '/' . $id;
            if (!is_file($dir . '/module.json')) {
                throw new PackageException('bad_layout', 'Package has no module.json');
            }
            $manifest = $this->validator->fromJson((string) file_get_contents($dir . '/module.json'), $id);
            $sig = $this->verifier->verify($dir, $manifest);
            $others = $installed;
            unset($others[$manifest->id]);
            $this->resolver->assert($manifest, $others);
            $this->scanner->assertClean($dir);
            $this->assertEntryFile($dir, $manifest);

            $current = $installed[$manifest->id] ?? null;
            if ($current !== null && !$allowDowngrade && $manifest->version->compare($current->version) < 0) {
                throw new PackageException('downgrade', 'A newer version of this module is already installed');
            }

            $base = rtrim($this->modulesDir, '/\\') . '/' . $manifest->id;
            if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
                throw new PackageException('fs', 'Modules directory is not writable');
            }
            $target = $base . '/' . (string) $manifest->version;
            $replaced = false;
            if (is_dir($target)) {
                // Same version re-upload: swap atomically, keep the old copy until the rename succeeded.
                $old = $target . '.old-' . bin2hex(random_bytes(4));
                if (!@rename($target, $old)) {
                    throw new PackageException('fs', 'Existing module version cannot be replaced');
                }
                if (!@rename($dir, $target)) {
                    @rename($old, $target);
                    throw new PackageException('fs', 'Module cannot be moved into place');
                }
                Fs::remove($old);
                $replaced = true;
            } elseif (!@rename($dir, $target)) {
                throw new PackageException('fs', 'Module cannot be moved into place');
            }
            return new InstallResult($manifest, $target, $sig['signed'], $sig['key'], $replaced);
        } catch (ModulyException $e) {
            throw $e;
        } finally {
            Fs::remove($quarantine);
        }
    }

    private function assertEntryFile(string $dir, Manifest $m): void
    {
        foreach ($m->psr4 as $ns => $rel) {
            if (str_starts_with($m->entry, $ns)) {
                $file = $dir . '/' . $rel . str_replace('\\', '/', substr($m->entry, strlen($ns))) . '.php';
                if (is_file($file)) {
                    return;
                }
            }
        }
        throw new PackageException('missing_entry', 'Entry class file is missing');
    }
}
