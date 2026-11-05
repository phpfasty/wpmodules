<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Moduly\Wp\SafeMode;
use PHPUnit\Framework\TestCase;

/**
 * Safe-mode token is header-only (DoD). Query-string ?moduly_safe= must never authenticate.
 */
final class SafeModeTest extends TestCase
{
    private const TOKEN = '0123456789abcdef';

    public function testQueryStringTokenIsIgnored(): void
    {
        $_GET['moduly_safe'] = self::TOKEN;
        $this->assertFalse(SafeMode::active(), 'GET moduly_safe must not activate safe mode');
    }

    public function testHeaderTokenActivatesSafeMode(): void
    {
        $_SERVER['HTTP_X_MODULY_SAFE'] = self::TOKEN;
        $this->assertTrue(SafeMode::active());
    }

    public function testHeaderWinsOverMatchingQueryString(): void
    {
        $_GET['moduly_safe'] = 'wrong-token-value!!!!';
        $_SERVER['HTTP_X_MODULY_SAFE'] = self::TOKEN;
        $this->assertTrue(SafeMode::active());
    }

    public function testWrongHeaderIsRejected(): void
    {
        $_SERVER['HTTP_X_MODULY_SAFE'] = 'not-the-configured-token';
        $this->assertFalse(SafeMode::active());
    }

    public function testShortTokenNeverMatches(): void
    {
        // Token constant is ≥16; a short header value still fails hash_equals length / active check.
        $_SERVER['HTTP_X_MODULY_SAFE'] = 'short';
        $this->assertFalse(SafeMode::active());
    }

    public function testEnvFlagActivatesWithoutToken(): void
    {
        putenv('MODULY_SAFE_MODE=1');
        $this->assertTrue(SafeMode::active());
    }

    public function testSourceRejectsGetParamPath(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__).'/moduly/src/SafeMode.php');
        $this->assertStringNotContainsString('$_GET', $src);
        $this->assertStringNotContainsString('moduly_safe', $src);
        $this->assertStringContainsString('HTTP_X_MODULY_SAFE', $src);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('MODULY_SAFE_TOKEN')) {
            define('MODULY_SAFE_TOKEN', self::TOKEN);
        }
        putenv('MODULY_SAFE_MODE');
        unset($_GET['moduly_safe'], $_SERVER['HTTP_X_MODULY_SAFE']);
    }

    protected function tearDown(): void
    {
        putenv('MODULY_SAFE_MODE');
        unset($_GET['moduly_safe'], $_SERVER['HTTP_X_MODULY_SAFE']);
        parent::tearDown();
    }
}
