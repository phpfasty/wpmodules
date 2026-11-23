<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\SitePulse\Domain\ApiKey;
use PhpFasty\Module\SitePulse\Domain\ConsentMode;
use PhpFasty\Module\SitePulse\Domain\InvalidInputException;
use PhpFasty\Module\SitePulse\Domain\Keywords;
use PhpFasty\Module\SitePulse\Domain\Region;
use PhpFasty\Module\SitePulse\Domain\ServerUrl;
use PhpFasty\Module\SitePulse\Domain\Settings;
use PhpFasty\Module\SitePulse\Domain\TrackerTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SitePulse settings domain: server URL (SSRF rules), keys, consent mode, keywords, tracker tag. */
final class SitePulseConfigTest extends TestCase
{
    // Synthetic fixtures, assembled at runtime so secret scanners do not mistake them for real keys.
    private const TAIL = '0123456789abcdef0123456789abcdef';

    /** @return iterable<string, array{string, string}> */
    public static function validUrls(): iterable
    {
        yield 'https' => ['https://pulse.example.com', 'https://pulse.example.com'];
        yield 'trailing slash, case' => [' HTTPS://Pulse.Example.COM/ ', 'https://pulse.example.com'];
        yield 'port and path' => ['https://pulse.example.com:8443/api/', 'https://pulse.example.com:8443/api'];
        yield 'nested path' => ['https://h.example/a/b.c_d~e-f', 'https://h.example/a/b.c_d~e-f'];
        yield 'http localhost' => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'http 127.0.0.1' => ['http://127.0.0.1:8080/', 'http://127.0.0.1:8080'];
        yield 'http ::1' => ['http://[::1]:8080', 'http://[::1]:8080'];
        yield 'http *.localhost' => ['http://pulse.localhost', 'http://pulse.localhost'];
    }

    #[DataProvider('validUrls')]
    public function testValidServerUrls(string $input, string $base): void
    {
        $this->assertSame($base, ServerUrl::fromString($input)->base());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidUrls(): iterable
    {
        yield 'empty' => ['', 'sitepulse_invalid_url'];
        yield 'blank' => ['   ', 'sitepulse_invalid_url'];
        yield 'relative' => ['/v1', 'sitepulse_invalid_url'];
        yield 'no scheme' => ['pulse.example.com', 'sitepulse_invalid_url'];
        yield 'credentials' => ['https://user:pw@pulse.example.com', 'sitepulse_invalid_url'];
        yield 'password only' => ['https://:pw@pulse.example.com', 'sitepulse_invalid_url'];
        yield 'query' => ['https://pulse.example.com/?a=1', 'sitepulse_invalid_url'];
        yield 'fragment' => ['https://pulse.example.com/#x', 'sitepulse_invalid_url'];
        yield 'bad path char' => ['https://pulse.example.com/a%20b', 'sitepulse_invalid_url'];
        yield 'dot segment' => ['https://pulse.example.com/..', 'sitepulse_invalid_url'];
        yield 'dot segment inside' => ['https://pulse.example.com/a/./b', 'sitepulse_invalid_url'];
        yield 'http public' => ['http://pulse.example.com', 'sitepulse_insecure_url'];
        yield 'http lookalike' => ['http://evillocalhost', 'sitepulse_insecure_url'];
        yield 'http private ip' => ['http://10.0.0.5', 'sitepulse_insecure_url'];
        yield 'ftp' => ['ftp://localhost', 'sitepulse_insecure_url'];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidServerUrls(string $input, string $code): void
    {
        try {
            ServerUrl::fromString($input);
            $this->fail('accepted '.$input);
        } catch (InvalidInputException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertSame(400, $e->httpStatus());
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testHostAndLoopback(): void
    {
        $this->assertSame('pulse.example.com', ServerUrl::fromString('https://PULSE.example.com/x')->host());
        $this->assertTrue(ServerUrl::isLoopback('localhost'));
        $this->assertTrue(ServerUrl::isLoopback('127.0.0.1'));
        $this->assertTrue(ServerUrl::isLoopback('[::1]'));
        $this->assertTrue(ServerUrl::isLoopback('a.localhost'));
        $this->assertFalse(ServerUrl::isLoopback('notlocalhost'));
        $this->assertFalse(ServerUrl::isLoopback('localhost.example.com'));
        $this->assertFalse(ServerUrl::isLoopback('127.0.0.2'));
    }

    public function testEndpointsAreFixedPathsOnly(): void
    {
        $s = ServerUrl::fromString('https://pulse.example.com/base');
        $this->assertSame('https://pulse.example.com/base/v1/analyze', $s->endpoint('/v1/analyze'));
        $this->assertSame('https://pulse.example.com/base/v1/sites/site_1/articles/a.b-c:d', $s->endpoint('/v1/sites/site_1/articles/a.b-c:d'));
        $this->assertSame('https://pulse.example.com/base/sitepulse.js', $s->endpoint('/sitepulse.js'));
        foreach (['/v1', '/v1/', '/v2/analyze', 'v1/analyze', '/v1/../admin', '/v1/.hidden', '/v1/a b', '/v1/a?x=1', '/sitepulse.jsx', '//evil.example/v1/x', '/v1/x/'] as $bad) {
            try {
                $s->endpoint($bad);
                $this->fail('accepted '.$bad);
            } catch (InvalidInputException $e) {
                $this->assertSame('sitepulse_invalid_path', $e->errorCode());
            }
        }
    }

    public function testKeyValidation(): void
    {
        $this->assertTrue(ApiKey::isValid(ApiKey::SECRET, self::sk()));
        $this->assertTrue(ApiKey::isValid(ApiKey::PUBLIC, self::pk()));
        $this->assertTrue(ApiKey::isValid('sk', 'sk_test_'.str_repeat('a', 16)));
        $this->assertTrue(ApiKey::isValid('sk', 'sk_live_'.str_repeat('Z', 128)));
        $this->assertFalse(ApiKey::isValid('sk', 'sk_live_'.str_repeat('a', 15)));
        $this->assertFalse(ApiKey::isValid('sk', 'sk_live_'.str_repeat('a', 129)));
        $this->assertFalse(ApiKey::isValid(ApiKey::SECRET, self::pk()));
        $this->assertFalse(ApiKey::isValid(ApiKey::PUBLIC, self::sk()));
        $this->assertFalse(ApiKey::isValid('st', 'st_live_'.str_repeat('a', 20)));
        $this->assertFalse(ApiKey::isValid('sk', 'x'.self::sk()));
        $this->assertFalse(ApiKey::isValid('sk', self::sk().'!'));
        $this->assertFalse(ApiKey::isValid('sk', 'sk_prod_'.str_repeat('a', 20)));
        $this->assertFalse(ApiKey::isValid('sk', ''));
    }

    public function testMaskNeverShowsTheSecret(): void
    {
        $this->assertSame('', ApiKey::mask(''));
        $this->assertSame('sk_live_…1234', ApiKey::mask(self::sk()));
        $this->assertSame('pk_test_…wxyz', ApiKey::mask(sprintf('%s_test_%s', 'pk', 'abcdefghwxyz')));
        $this->assertSame('…cdef', ApiKey::mask('0123456789abcdef'));
        $this->assertSame('…89ab', ApiKey::mask('0123456789ab'));
        $this->assertSame('…', ApiKey::mask('0123456789a'));
        $this->assertStringNotContainsString('0123456789abcdef0123', ApiKey::mask(self::sk()));
    }

    public function testResolveSecret(): void
    {
        $old = self::sk();
        $new = 'sk_live_'.str_repeat('b', 36);
        $this->assertSame('', ApiKey::resolveSecret($old, $new, true));
        $this->assertSame($old, ApiKey::resolveSecret($old, '', false));
        $this->assertSame($old, ApiKey::resolveSecret($old, "  \n", false));
        $this->assertSame($new, ApiKey::resolveSecret($old, ' '.$new.' ', false));
        $this->assertSame($new, ApiKey::resolveSecret('', $new, false));
        try {
            ApiKey::resolveSecret($old, self::pk(), false);
            $this->fail('pk accepted as secret');
        } catch (InvalidInputException $e) {
            $this->assertSame('sitepulse_invalid_secret', $e->errorCode());
        }
    }

    public function testConsentModeDefaultsToRequire(): void
    {
        $this->assertSame(ConsentMode::Off, ConsentMode::fromInput('off'));
        $this->assertSame(ConsentMode::WaitForConsent, ConsentMode::fromInput('require'));
        $this->assertSame(ConsentMode::WaitForConsent, ConsentMode::fromInput('OFF'));
        $this->assertSame(ConsentMode::WaitForConsent, ConsentMode::fromInput(null));
        $this->assertSame(ConsentMode::WaitForConsent, ConsentMode::fromInput(0));
    }

    public function testKeywords(): void
    {
        $this->assertSame([], Keywords::normalize(null));
        $this->assertSame([], Keywords::normalize(42));
        $this->assertSame(['go', 'docker compose'], Keywords::normalize(' go ,  docker   compose ,, '));
        $this->assertSame(['Go', 'Kubernetes'], Keywords::normalize(['Go', 'go', 7, ['x'], ' ', "Kubernetes\t"]));
        $this->assertSame(['Привет мир'], Keywords::normalize(['Привет мир', 'ПРИВЕТ  МИР']));
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], Keywords::normalize(['a', 'A', 'b', 'c', 'd', 'e', 'f']));
        $long = str_repeat('ж', 101);
        $this->assertSame([str_repeat('ж', 100)], Keywords::normalize([$long]));
        $this->assertSame([str_repeat('ж', 100)], Keywords::normalize([$long, str_repeat('ж', 100)]));
        $this->assertSame(['x'], Keywords::normalize(['x', "\xff"]));
    }

    public function testTrackerTagMatchesTheInstallSnippet(): void
    {
        $tag = TrackerTag::create(ServerUrl::fromString('http://127.0.0.1:8080/'), self::pk());
        $this->assertSame('<script src="http://127.0.0.1:8080/sitepulse.js" data-site="'.self::pk().'" defer></script>', $tag->html());
        $this->assertSame('http://127.0.0.1:8080/sitepulse.js', $tag->src);
        $this->assertSame(self::pk(), $tag->siteKey);
        try {
            TrackerTag::create(ServerUrl::fromString('https://p.example'), self::sk());
            $this->fail('secret accepted as tracker key');
        } catch (InvalidInputException $e) {
            $this->assertSame('sitepulse_invalid_public_key', $e->errorCode());
        }
    }

    public function testSettingsFromStored(): void
    {
        $d = Settings::fromStored('garbage');
        $this->assertSame(Settings::DEFAULTS, $d->toArray());
        $this->assertNull($d->server());
        $this->assertNull($d->trackerTag());
        $s = Settings::fromStored(['server_url' => 5, 'tracker_enabled' => '1', 'auto_analyze' => 1, 'consent_mode' => 'off', 'public_key' => ['x']]);
        $this->assertFalse($s->trackerEnabled);
        $this->assertFalse($s->autoAnalyze);
        $this->assertSame(ConsentMode::Off, $s->consentMode);
        $this->assertSame('', $s->serverUrl);
        $this->assertSame('', $s->publicKey);
        $this->assertNull(Settings::fromStored(['server_url' => 'http://example.com'])->server());
    }

    public function testSettingsTrackerTag(): void
    {
        $base = ['server_url' => 'https://api.example.com', 'public_key' => self::pk(), 'tracker_enabled' => true, 'auto_analyze' => true];
        $s = Settings::fromStored($base);
        $this->assertTrue($s->autoAnalyze);
        $this->assertSame('https://api.example.com', $s->server()?->base());
        $this->assertSame('https://api.example.com/sitepulse.js', $s->trackerTag()?->src);
        $s = Settings::fromStored($base + ['tracker_url' => 'https://in.example.com']);
        $this->assertSame('https://in.example.com/sitepulse.js', $s->trackerTag()?->src);
        $this->assertSame('https://api.example.com', $s->server()?->base());
        $this->assertNull(Settings::fromStored(['tracker_url' => 'http://in.example.com'] + $base)->trackerTag());
        $this->assertNull(Settings::fromStored(['tracker_url' => 'http://in.example.com'] + $base)->trackerServer());
        $this->assertNull(Settings::fromStored(['tracker_enabled' => false] + $base)->trackerTag());
        $this->assertNull(Settings::fromStored(['public_key' => 'pk_live_short'] + $base)->trackerTag());
        $this->assertNull(Settings::fromStored(['server_url' => ''] + $base)->trackerTag());
        $this->assertSame('https://in.example.com', Settings::fromStored(['server_url' => '', 'tracker_url' => 'https://in.example.com'] + $base)->trackerServer()?->base());
    }

    public function testSettingsSanitize(): void
    {
        $prev = Settings::fromStored(['server_url' => 'https://old.example.com', 'tracker_url' => 'https://t.example.com', 'public_key' => self::pk(), 'tracker_enabled' => true, 'consent_mode' => 'off', 'auto_analyze' => true]);
        [$out, $errors] = Settings::sanitize([
            'server_url' => ' https://New.example.com/ ',
            'tracker_url' => '',
            'public_key' => ' '.self::pk().' ',
            'tracker_enabled' => '1',
            'consent_mode' => 'require',
            'auto_analyze' => 'on',
        ], $prev);
        $this->assertSame([], $errors);
        $this->assertSame([
            'region' => 'custom',
            'server_url' => 'https://new.example.com',
            'tracker_url' => '',
            'public_key' => self::pk(),
            'tracker_enabled' => true,
            'consent_mode' => 'require',
            'auto_analyze' => true,
        ], $out);

        [$out, $errors] = Settings::sanitize(['server_url' => 'http://evil.example.com', 'tracker_url' => 'ftp://x', 'public_key' => 'nope', 'tracker_enabled' => true], $prev);
        $this->assertCount(3, $errors);
        $this->assertSame('https://old.example.com', $out['server_url']);
        $this->assertSame('https://t.example.com', $out['tracker_url']);
        $this->assertSame(self::pk(), $out['public_key']);
        $this->assertTrue($out['tracker_enabled']);
        $this->assertFalse($out['auto_analyze']);
        $this->assertSame('require', $out['consent_mode']);
        $this->assertStringContainsString('pk_live_', $errors[2]);

        [$out, $errors] = Settings::sanitize(['server_url' => ['x'], 'public_key' => '', 'tracker_enabled' => '0', 'auto_analyze' => 'yes', 'consent_mode' => 'off'], $prev);
        $this->assertSame([], $errors);
        $this->assertSame('', $out['server_url']);
        $this->assertSame('', $out['public_key']);
        $this->assertFalse($out['tracker_enabled']);
        $this->assertFalse($out['auto_analyze']);
        $this->assertSame('off', $out['consent_mode']);
    }

    public function testRegionPresets(): void
    {
        $this->assertSame(Region::Fra, Region::fromInput('fra'));
        $this->assertSame(Region::Ru, Region::fromInput('ru'));
        $this->assertSame(Region::Custom, Region::fromInput('custom'));
        $this->assertSame(Region::Custom, Region::fromInput('FRA'));
        $this->assertSame(Region::Custom, Region::fromInput(null));
        $this->assertSame(['https://pulse.yself.link', 'https://events.yself.link', 'FRA (EU)', true], [Region::Fra->apiUrl(), Region::Fra->trackerUrl(), Region::Fra->label(), Region::Fra->requiresConsent()]);
        $this->assertSame(['https://pulse.yself.ru.net', 'https://events.yself.ru.net', 'RU', false], [Region::Ru->apiUrl(), Region::Ru->trackerUrl(), Region::Ru->label(), Region::Ru->requiresConsent()]);
        $this->assertSame(['', '', 'Custom', false], [Region::Custom->apiUrl(), Region::Custom->trackerUrl(), Region::Custom->label(), Region::Custom->requiresConsent()]);
        foreach ([Region::Fra, Region::Ru] as $r) {
            $this->assertSame($r->apiUrl(), ServerUrl::fromString($r->apiUrl())->base());
            $this->assertSame($r->trackerUrl(), ServerUrl::fromString($r->trackerUrl())->base());
        }
    }

    public function testPresetSettingsUseBothOrigins(): void
    {
        $s = Settings::fromStored(['region' => 'fra', 'server_url' => 'https://stale.example', 'tracker_url' => '', 'public_key' => self::pk(), 'tracker_enabled' => true]);
        $this->assertSame(Region::Fra, $s->region);
        $this->assertSame('https://pulse.yself.link', $s->server()?->base());
        $this->assertSame('https://events.yself.link/sitepulse.js', $s->trackerTag()?->src);
        $tag = $s->trackerTag();
        $this->assertSame('<script src="https://events.yself.link/sitepulse.js" data-site="'.self::pk().'" defer></script>', $tag->html());
        $ru = Settings::fromStored(['region' => 'ru', 'public_key' => self::pk(), 'tracker_enabled' => true]);
        $this->assertSame('https://pulse.yself.ru.net', $ru->serverUrl);
        $this->assertSame('https://events.yself.ru.net', $ru->trackerUrl);
        $local = Settings::fromStored(['region' => 'custom', 'server_url' => 'http://localhost:8080', 'tracker_url' => 'http://localhost:8080']);
        $this->assertSame('http://localhost:8080', $local->server()?->base());
        $this->assertSame('http://localhost:8080', $local->trackerServer()?->base());
    }

    public function testPresetSanitize(): void
    {
        $custom = Settings::fromStored(['region' => 'custom', 'server_url' => 'http://localhost:8080', 'consent_mode' => 'off']);
        [$out, $errors] = Settings::sanitize(['region' => 'fra', 'server_url' => 'http://evil.example', 'tracker_url' => 'ftp://x', 'consent_mode' => 'off'], $custom);
        $this->assertSame([], $errors);
        $this->assertSame(['fra', 'https://pulse.yself.link', 'https://events.yself.link', 'require'], [$out['region'], $out['server_url'], $out['tracker_url'], $out['consent_mode']]);
        $fra = Settings::fromStored($out);
        [$out] = Settings::sanitize(['region' => 'fra', 'consent_mode' => 'off'], $fra);
        $this->assertSame('off', $out['consent_mode'], 'EU preset only switches consent on when it is chosen');
        [$out] = Settings::sanitize(['region' => 'ru', 'consent_mode' => 'off'], $custom);
        $this->assertSame(['ru', 'https://pulse.yself.ru.net', 'https://events.yself.ru.net', 'off'], [$out['region'], $out['server_url'], $out['tracker_url'], $out['consent_mode']]);
        [$out] = Settings::sanitize(['region' => 'custom', 'server_url' => 'http://localhost:8080', 'tracker_url' => 'http://localhost:8080/'], $fra);
        $this->assertSame(['custom', 'http://localhost:8080', 'http://localhost:8080'], [$out['region'], $out['server_url'], $out['tracker_url']]);
    }

    private static function sk(): string
    {
        return sprintf('%s_live_%s1234', 'sk', self::TAIL);
    }

    private static function pk(): string
    {
        return sprintf('%s_live_%s5678', 'pk', self::TAIL);
    }
}
