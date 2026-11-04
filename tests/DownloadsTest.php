<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\Downloads\Token;
use PhpFasty\Moduly\Wp\Support\Value;
use PHPUnit\Framework\TestCase;

/** Signed links: 24 h expiry, tamper, unpaid and limit are all refused (served as 403). */
final class DownloadsTest extends TestCase
{
    private const SECRET = 'k';

    public function testValidLinkWithinTtl(): void
    {
        $now = 1_800_000_000;
        $e = $now + 24 * 3600;
        $s = Token::sign(10, 0, $e, self::SECRET);
        $this->assertTrue(Token::valid(10, 0, $e, $s, self::SECRET, $now));
        $this->assertTrue(Token::valid(10, 0, $e, $s, self::SECRET, $e));
    }

    public function testExpiredOrTamperedLinkIsInvalid(): void
    {
        $now = 1_800_000_000;
        $e = $now + 60;
        $s = Token::sign(10, 0, $e, self::SECRET);
        $this->assertFalse(Token::valid(10, 0, $e, $s, self::SECRET, $e + 1), 'expired');
        $this->assertFalse(Token::valid(11, 0, $e, $s, self::SECRET, $now), 'other order');
        $this->assertFalse(Token::valid(10, 1, $e, $s, self::SECRET, $now), 'other item');
        $this->assertFalse(Token::valid(10, 0, $e + 86400, $s, self::SECRET, $now), 'extended expiry');
        $this->assertFalse(Token::valid(10, 0, $e, $s, 'other-secret', $now), 'other site');
        $this->assertFalse(Token::valid(10, 0, $e, '', self::SECRET, $now));
        // the message format is part of the link contract: order|item|expires
        $this->assertSame('974b52170958052de8c8dee9cd2947272c55535cbb0af19e6cac264374e36e93', Token::sign(12, 3, 1700000000, 'k'));
        $this->assertNotSame(Token::sign(1, 23, 5, 'k'), Token::sign(12, 3, 5, 'k'));
        $z = Token::sign(0, 0, $e, self::SECRET);
        $this->assertFalse(Token::valid(0, 0, $e, $z, self::SECRET, $now), 'order 0');
        $m = Token::sign(10, -1, $e, self::SECRET);
        $this->assertFalse(Token::valid(10, -1, $e, $m, self::SECRET, $now), 'negative item');
    }

    public function testGate(): void
    {
        $ok = ['valid' => true, 'paid' => true, 'count' => 0, 'limit' => 5, 'file' => true];
        $this->assertSame('', Token::deny($ok));
        $this->assertSame('invalid', Token::deny(['valid' => false] + $ok));
        $this->assertSame('unpaid', Token::deny(['paid' => false] + $ok));
        $this->assertSame('limit', Token::deny(['count' => 5] + $ok));
        $this->assertSame('missing', Token::deny(['file' => false] + $ok));
    }

    public function testEndpointIsDeclaredForTheDisableScenario(): void
    {
        $m = json_decode((string) file_get_contents(dirname(__DIR__).'/modules/downloads/module.json'), true);
        $this->assertSame(['moduly_download'], Value::at(Value::at($m, 'endpoints'), 'actions'));
        $this->assertContains('files:private', Value::arr(Value::at($m, 'permissions')));
    }
}
