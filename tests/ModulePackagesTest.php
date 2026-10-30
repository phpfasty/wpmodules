<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Moduly\Manifest\DependencyResolver;
use PhpFasty\Moduly\Manifest\ManifestValidator;
use PhpFasty\Moduly\Package\Fs;
use PhpFasty\Moduly\Package\Installer;
use PhpFasty\Moduly\Package\Packer;
use PhpFasty\Moduly\Scan\StaticScanner;
use PhpFasty\Moduly\Signature\Keyring;
use PhpFasty\Moduly\Signature\Signer;
use PhpFasty\Moduly\Signature\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every module in modules/ passes the same pipeline the plugin runs: schema, scan, sign → pack → safe install. */
final class ModulePackagesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wpmodules-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        Fs::remove($this->tmp);
    }

    /** @return iterable<string, array{string}> */
    public static function modules(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            yield basename($dir) => [$dir];
        }
    }

    #[DataProvider('modules')]
    public function testManifestIsValid(string $dir): void
    {
        $m = (new ManifestValidator())->fromJson((string) file_get_contents($dir . '/module.json'), basename($dir));
        $this->assertSame(basename($dir), $m->id);
    }

    #[DataProvider('modules')]
    public function testStaticScanIsClean(string $dir): void
    {
        (new StaticScanner())->assertClean($dir);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('modules')]
    public function testModuleCodeNeverDeletesData(string $dir): void
    {
        foreach (Fs::files($dir) as $rel) {
            $code = (string) file_get_contents($dir . '/' . $rel);
            if (str_contains($code, '@moduly-destructive-cli')) {
                // Explicit WP-CLI deletes (backup first, --yes): must not hook into requests.
                $this->assertDoesNotMatchRegularExpression('/\b(add_action|add_filter|->on)\s*\(/', $code, $rel);
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/\b(delete_post_meta|wp_delete_post|DROP\s+TABLE|TRUNCATE)\b/i', $code, $rel);
        }
    }

    public function testSignedPackagesInstallInDependencyOrder(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $installer = new Installer(
            $this->tmp . '/modules',
            $this->tmp . '/quarantine',
            new Verifier(new Keyring(['phpfasty' => [$public]])),
            new DependencyResolver('0.1.0', PHP_VERSION, ['wp' => '6.8.0']),
        );
        $installed = [];
        foreach (['cart', 'orders'] as $id) {
            $stage = $this->tmp . '/stage/' . $id;
            mkdir($stage, 0700, true);
            $src = dirname(__DIR__) . '/modules/' . $id;
            foreach (Fs::files($src) as $rel) {
                @mkdir(dirname($stage . '/' . $rel), 0700, true);
                copy($src . '/' . $rel, $stage . '/' . $rel);
            }
            Signer::sign($stage, $secret);
            Packer::pack($stage, $this->tmp . "/$id.zip");
            $result = $installer->install($this->tmp . "/$id.zip", $installed);
            $this->assertTrue($result->signed);
            $installed[$id] = $result->manifest;
        }
        $this->assertSame(['cart', 'orders'], array_keys($installed));
    }

    public function testTamperedPackageIsRejected(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $stage = $this->tmp . '/stage/cart';
        mkdir($stage, 0700, true);
        $src = dirname(__DIR__) . '/modules/cart';
        foreach (Fs::files($src) as $rel) {
            @mkdir(dirname($stage . '/' . $rel), 0700, true);
            copy($src . '/' . $rel, $stage . '/' . $rel);
        }
        Signer::sign($stage, base64_encode(sodium_crypto_sign_secretkey($pair)));
        file_put_contents($stage . '/src/Money.php', "\n// tampered\n", FILE_APPEND);
        Packer::pack($stage, $this->tmp . '/cart.zip');
        $installer = new Installer(
            $this->tmp . '/modules',
            $this->tmp . '/quarantine',
            new Verifier(new Keyring(['phpfasty' => [base64_encode(sodium_crypto_sign_publickey($pair))]])),
            new DependencyResolver('0.1.0', PHP_VERSION, ['wp' => '6.8.0']),
        );
        $this->expectException(\RuntimeException::class);
        $installer->install($this->tmp . '/cart.zip');
    }
}
