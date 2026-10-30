<?php

/**
 * Publisher CLI from the vendored core (same as phpfasty/moduly bin/moduly): keygen | sign | pack | verify.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PhpFasty\\Moduly\\')) {
        $file = __DIR__ . '/../moduly/lib/core/src/' . str_replace('\\', '/', substr($class, 16)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
if (!function_exists('sodium_crypto_sign_detached') && is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Moduly;
use PhpFasty\Moduly\Package\Fs;
use PhpFasty\Moduly\Package\Installer;
use PhpFasty\Moduly\Package\Packer;
use PhpFasty\Moduly\Signature\Keyring;
use PhpFasty\Moduly\Signature\Signer;
use PhpFasty\Moduly\Signature\Verifier;

$cmd = $argv[1] ?? '';
try {
    switch ($cmd) {
        case 'sign':
            $key = getenv('MODULY_SIGNING_KEY');
            $sig = Signer::sign(rtrim($argv[2] ?? '', '/'), $key === false || $key === '' ? null : $key);
            fwrite(STDERR, sprintf("%s: %s, %d files\n", basename($argv[2] ?? ''), $sig->alg, count($sig->files)));
            break;
        case 'pack':
            Packer::pack(rtrim($argv[2] ?? '', '/'), $argv[3] ?? '');
            break;
        case 'zipdir':
            // Reproducible zip of a folder (the folder name becomes the top-level entry).
            $dir = rtrim($argv[2] ?? '', '/');
            $zip = new ZipArchive();
            $zip->open($argv[3] ?? '', ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach (Fs::files($dir) as $rel) {
                $name = basename($dir) . '/' . $rel;
                $zip->addFile($dir . '/' . $rel, $name);
                $zip->setMtimeName($name, 315532800);
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
            }
            $zip->close();
            break;
        case 'verify':
            $tmp = sys_get_temp_dir() . '/moduly-verify-' . bin2hex(random_bytes(4));
            $installed = [];
            $installer = new Installer($tmp . '/m', $tmp . '/q', new Verifier(new Keyring([$argv[3] ?? '' => [$argv[4] ?? '']])), new DependencyResolver(Moduly::VERSION, PHP_VERSION, ['wp' => '99.0.0']));
            $byId = static fn (array $list): array => array_column(array_map(static fn ($m) => [$m->id, $m], $list), 1, 0);
            foreach (array_slice($argv, 5) as $dep) {
                // Each dependency is checked against the ones listed before it (cart before orders).
                $installed[] = $installer->install($dep, $byId($installed))->manifest;
            }
            $r = $installer->install($argv[2] ?? '', $byId($installed));
            fwrite(STDERR, sprintf("OK %s %s signed=%s\n", $r->manifest->id, (string) $r->manifest->version, $r->signed ? 'yes' : 'no'));
            Fs::remove($tmp);
            break;
        default:
            fwrite(STDERR, "usage: php tools/moduly.php sign <dir> | pack <dir> <zip> | verify <zip> <publisher> <pubkey> [dep.zip…]\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
