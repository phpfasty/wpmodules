<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\CookieConsent\CookieConsent;
use PHPUnit\Framework\TestCase;

final class CookieConsentTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[CookieConsent::COOKIE]);
    }

    public function testAnalyticsOnlyAfterAcceptAll(): void
    {
        $this->assertFalse(CookieConsent::analyticsAllowed());
        $_COOKIE[CookieConsent::COOKIE] = 'necessary';
        $this->assertFalse(CookieConsent::analyticsAllowed());
        $_COOKIE[CookieConsent::COOKIE] = 'all';
        $this->assertTrue(CookieConsent::analyticsAllowed());
    }

    public function testScriptMakesNoExternalRequests(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/modules/cookie-consent/assets/cookie-consent.js');
        $this->assertDoesNotMatchRegularExpression('#https?://|fetch\(|XMLHttpRequest|\.src\s*=#', $js);
        $this->assertStringContainsString("value !== 'all' && value !== 'necessary'", $js);
    }

    public function testDisabledStateIsTheThemeDefault(): void
    {
        // Theme default when the module is off: no `enabled` key → banner hidden.
        $state = CookieConsent::state([]);
        $this->assertTrue($state['enabled']);
        $this->assertSame(CookieConsent::COOKIE, $state['cookie']);
    }
}
