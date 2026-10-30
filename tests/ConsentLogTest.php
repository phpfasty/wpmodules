<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\ConsentLog\Consents;
use PhpFasty\Module\ConsentLog\Privacy;
use PHPUnit\Framework\TestCase;

final class ConsentLogTest extends TestCase
{
    protected function setUp(): void
    {
        Consents::configure(['legal_version' => '2026-10-30']);
    }

    public function testOfferAndPersonalDataAreRequiredNewsletterIsNot(): void
    {
        $this->assertSame(['consent_offer', 'consent_pd'], Consents::validate([], []));
        $this->assertSame([], Consents::validate([], ['consent_offer' => true, 'consent_pd' => true]));
        $this->assertSame(['email', 'consent_pd'], Consents::validate(['email'], ['consent_offer' => true]));
    }

    public function testRecordKeepsProof(): void
    {
        $r = Consents::record(['consent_offer' => true, 'consent_pd' => true], '203.0.113.5', '2026-10-30T12:00:00+00:00');
        $this->assertSame(['time' => '2026-10-30T12:00:00+00:00', 'ip' => '203.0.113.5', 'version' => '2026-10-30', 'offer' => true, 'personal_data' => true, 'newsletter' => false], $r);
    }

    public function testEraserAnonymizesButKeepsTheProof(): void
    {
        $log = Privacy::anonymizeLog(['time' => 't', 'ip' => '203.0.113.5', 'offer' => true]);
        $this->assertSame('', $log['ip']);
        $this->assertTrue($log['offer']);
        $this->assertTrue($log['anonymized']);
        $this->assertSame('[deleted]', Privacy::anonymous('name'));
        $this->assertSame('', Privacy::anonymous('phone'));
    }
}
