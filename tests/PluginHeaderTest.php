<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PHPUnit\Framework\TestCase;

final class PluginHeaderTest extends TestCase
{
    public function testHeaderNamesModulYByPhpFastY(): void
    {
        $head = (string) file_get_contents(dirname(__DIR__) . '/moduly/moduly.php', false, null, 0, 4096);
        $this->assertMatchesRegularExpression('/^ \* Plugin Name: +ModulY$/m', $head);
        $this->assertMatchesRegularExpression('/^ \* Author: +PHP FastY$/m', $head);
        $this->assertMatchesRegularExpression('/^ \* License: +AGPL-3.0-or-later$/m', $head);
        $this->assertMatchesRegularExpression('/^ \* Text Domain: +moduly$/m', $head);
    }

    public function testVendoredCoreMatchesPluginVersion(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/moduly/lib/core/VERSION');
        $this->assertFileExists(dirname(__DIR__) . '/moduly/lib/core/schema/module-1.json');
    }
}
