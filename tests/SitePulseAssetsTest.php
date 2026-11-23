<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PHPUnit\Framework\TestCase;

/** Browser assets of the SitePulse module: no secret paths, consent loader requests nothing before consent. */
final class SitePulseAssetsTest extends TestCase
{
    private const DIR = __DIR__.'/../modules/site-pulse';

    public function testEditorTalksToThePluginProxyOnly(): void
    {
        $js = (string) file_get_contents(self::DIR.'/assets/editor.js');
        $this->assertDoesNotMatchRegularExpression('#sk_live|Authorization|https?://|(?<!sitepulse)/v1/#', $js);
        $this->assertStringContainsString("path: '/sitepulse/v1/analyze'", $js);
        $this->assertStringContainsString("'/sitepulse/v1/insights/'", $js);
        $this->assertStringNotContainsString('innerHTML', $js);
    }

    public function testConsentLoaderInjectsTheTagOnlyFromStart(): void
    {
        $js = (string) file_get_contents(self::DIR.'/assets/consent-loader.js');
        $this->assertDoesNotMatchRegularExpression('#fetch\(|XMLHttpRequest|sendBeacon|localStorage|https?://#', $js);
        $this->assertSame(1, substr_count($js, "createElement('script')"));
        $start = (int) strpos($js, 'function start()');
        $end = (int) strpos($js, 'function cookie(');
        $this->assertGreaterThan($start, (int) strpos($js, "createElement('script')"));
        $this->assertLessThan($end, (int) strpos($js, "createElement('script')"));
        foreach (["'sitepulse:consent'", "'wpfasty:consent'", "'wpfasty_cookie_consent'", 'SitePulseConsent', "wp_has_consent('statistics')", "'CookiebotOnAccept'"] as $signal) {
            $this->assertStringContainsString($signal, $js);
        }
    }

    public function testSecretOptionIsNeverRegisteredForRest(): void
    {
        $php = (string) file_get_contents(self::DIR.'/src/AdminPage.php');
        $this->assertSame(2, substr_count($php, "'show_in_rest' => false"));
        $this->assertStringContainsString('value=""', $php);
    }
}
