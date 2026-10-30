<?php

declare(strict_types=1);

namespace PhpFasty\Module\Downloads;

// Namespaced WordPress stubs: Downloads/Storage resolve these before the global fallbacks.
/** @return array{basedir: string} */
function wp_upload_dir(?string $time = null, bool $create = true): array
{
    return ['basedir' => $GLOBALS['moduly_dl_base']];
}

function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}

function wp_unique_filename(string $dir, string $name): string
{
    return $name;
}

function get_attached_file(int $id): string
{
    return $GLOBALS['moduly_dl_files'][$id] ?? '';
}

function update_attached_file(int $id, string $file): bool
{
    $GLOBALS['moduly_dl_files'][$id] = $file;
    return true;
}

function get_post_type(int $id): string|false
{
    return $GLOBALS['moduly_dl_types'][$id] ?? false;
}

function current_user_can(string $cap, int ...$args): bool
{
    return false; // WP-CLI / cron / import: no logged-in user
}

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Downloads\Downloads;
use PhpFasty\Module\Downloads\Storage;
use PHPUnit\Framework\TestCase;

/** The download file moves into the private dir on any authorized save, with or without a logged-in user. */
final class DownloadsStorageTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/moduly-dl-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/2026/10', 0777, true);
        file_put_contents($this->base . '/2026/10/theme.zip', 'PK');
        $GLOBALS['moduly_dl_base'] = $this->base;
        $GLOBALS['moduly_dl_files'] = [27 => $this->base . '/2026/10/theme.zip'];
        $GLOBALS['moduly_dl_types'] = [8 => 'product', 27 => 'attachment', 9 => 'page', 28 => 'post'];
        Downloads::setRegistry(new class {
            public function value(int $id, string $field): int
            {
                return ['8' => 27, '9' => 27, '10' => 28][(string) $id] ?? 0;
            }
        });
    }

    public function testSaveWithoutCapabilityContextMovesTheFile(): void
    {
        Downloads::onSave(8);
        $path = $GLOBALS['moduly_dl_files'][27];
        $this->assertTrue(Storage::isPrivate($path), $path);
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($this->base . '/2026/10/theme.zip');
        $this->assertFileExists(Storage::dir() . '/.htaccess');
        $this->assertTrue(Downloads::protectProduct(8), 'idempotent: already private');
    }

    public function testMetaHookOnlyForTheFileField(): void
    {
        Downloads::onMeta(1, 8, '_shop_price');
        $this->assertFalse(Storage::isPrivate($GLOBALS['moduly_dl_files'][27]));
        Downloads::onMeta(1, 8, '_shop_download_file');
        $this->assertTrue(Storage::isPrivate($GLOBALS['moduly_dl_files'][27]));
    }

    public function testOnlyProductsAndAttachments(): void
    {
        $this->assertFalse(Downloads::protectProduct(9), 'not a product');
        $GLOBALS['moduly_dl_types'][10] = 'product';
        $this->assertFalse(Downloads::protectProduct(10), 'field points at a non-attachment');
        $this->assertFalse(Storage::isPrivate($GLOBALS['moduly_dl_files'][27]));
    }
}
