<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Moduly\Manifest\ManifestValidator;
use PhpFasty\Moduly\Wp\Unavailable;
use PHPUnit\Framework\TestCase;

/** Disable scenario: declared endpoints of modules that are not running are answered (503), never a blank page. */
final class UnavailableTest extends TestCase
{
    private static function manifest(string $id): \PhpFasty\Moduly\Manifest\Manifest
    {
        $json = (string) file_get_contents(dirname(__DIR__) . "/modules/$id/module.json");
        return (new ManifestValidator())->fromJson($json, $id);
    }

    public function testDisabledOrdersKeepsCheckoutAnswered(): void
    {
        $installed = ['cart' => self::manifest('cart'), 'orders' => self::manifest('orders')];
        $this->assertSame(['actions' => ['latte_shop_checkout'], 'rest' => []], Unavailable::collect($installed, ['cart']));
        $this->assertSame(['actions' => ['latte_shop_cart', 'latte_shop_checkout'], 'rest' => []], Unavailable::collect($installed, []));
        $this->assertSame(['actions' => [], 'rest' => []], Unavailable::collect($installed, ['cart', 'orders']));
    }

    public function testRestPrefixMatching(): void
    {
        $this->assertTrue(Unavailable::restMatches('/cart/v1/items', ['cart/v1']));
        $this->assertTrue(Unavailable::restMatches('/cart/v1', ['/cart/v1/']));
        $this->assertFalse(Unavailable::restMatches('/cart/v10', ['cart/v1']));
        $this->assertFalse(Unavailable::restMatches('/wp/v2/posts', ['cart/v1']));
    }

    public function testPageIsEscapedAndLinksBack(): void
    {
        $html = Unavailable::html('https://example.com/shop/?a=1&b="x"');
        $this->assertStringContainsString('Temporarily unavailable', $html);
        $this->assertStringContainsString('href="https://example.com/shop/?a=1&amp;b=&quot;x&quot;"', $html);
        $this->assertStringNotContainsString('href=""', Unavailable::html('https://example.com/'));
        $this->assertStringContainsString('href=""', Unavailable::html('javascript:alert(1)'));
    }

    /** Required by .cursor/rules: every admin-post action / REST namespace a module registers is declared. */
    public function testEveryModuleDeclaresItsEndpoints(): void
    {
        foreach (glob(dirname(__DIR__) . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $m = self::manifest(basename($dir));
            $code = '';
            foreach (glob($dir . '/src/*.php') ?: [] as $f) {
                $code .= (string) file_get_contents($f);
            }
            preg_match_all("/'admin_post(?:_nopriv)?_([a-z0-9_]+)'/", $code, $a);
            foreach (array_unique($a[1]) as $action) {
                $this->assertContains($action, $m->endpoints['actions'], basename($dir) . ": admin-post action $action not declared");
            }
            preg_match_all("/register_rest_route\\(\\s*'([^']+)'/", $code, $r);
            foreach (array_unique($r[1]) as $ns) {
                $this->assertTrue(Unavailable::restMatches($ns, $m->endpoints['rest']), basename($dir) . ": REST $ns not declared");
            }
        }
    }
}
