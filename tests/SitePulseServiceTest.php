<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\SitePulse\Domain\AnalyzeRequest;
use PhpFasty\Module\SitePulse\Domain\HttpResponse;
use PhpFasty\Module\SitePulse\Domain\InvalidInputException;
use PhpFasty\Module\SitePulse\Domain\SitePulseService;
use PhpFasty\Module\SitePulse\Domain\UpstreamException;
use PhpFasty\WpModules\Tests\Fake\FakeSitePulseClient;
use PHPUnit\Framework\TestCase;

/** The editor proxy's use cases against a scripted SitePulse server (contract of diagd 0.1.0). */
final class SitePulseServiceTest extends TestCase
{
    private const SITE = 'site_1a205f911b92be2c6e09882bd06a704';

    public function testAnalyzeProxiesTheContract(): void
    {
        $client = (new FakeSitePulseClient())->on('POST', '/v1/analyze', new HttpResponse(200, [
            'score' => 64, 'findings' => [['rule_id' => 'x', 'status' => 'warn', 'spans' => [['block_id' => 'p-3', 'start' => 1, 'end' => 4]]]],
        ]));
        $result = (new SitePulseService($client))->analyze(AnalyzeRequest::create('<p>a</p>', 'T', 'D', ['k']));
        $this->assertSame(64, $result->score);
        $this->assertSame([['method' => 'POST', 'path' => '/v1/analyze', 'query' => [], 'json' => ['html' => '<p>a</p>', 'title' => 'T', 'description' => 'D', 'keywords' => ['k']]]], $client->requests);
        $this->assertSame([['block_id' => 'p-3', 'start' => 1, 'end' => 4]], $result->toArray()['groups']['warn'][0]['spans']);
    }

    public function testAnalyzeMapsUpstreamErrors(): void
    {
        $client = (new FakeSitePulseClient())->on('POST', '/v1/analyze', new HttpResponse(401, ['status' => 401, 'code' => 'unauthorized', 'detail' => 'invalid api key'], 0));
        try {
            (new SitePulseService($client))->analyze(AnalyzeRequest::create('<p>a</p>', '', '', []));
            $this->fail('no error');
        } catch (UpstreamException $e) {
            $this->assertSame('sitepulse_auth', $e->errorCode());
            $this->assertSame(502, $e->httpStatus());
            $this->assertStringEndsWith('invalid api key', $e->getMessage());
        }
    }

    public function testSiteIdFromMe(): void
    {
        $client = (new FakeSitePulseClient())->on('GET', '/v1/me', new HttpResponse(200, ['kind' => 'sk', 'site_id' => self::SITE]));
        $this->assertSame(self::SITE, (new SitePulseService($client))->siteId());
        $this->assertSame('GET', $client->requests[0]['method']);
        foreach ([['kind' => 'user', 'site_id' => ''], ['site_id' => 'a/b'], ['site_id' => 5], []] as $body) {
            try {
                (new SitePulseService((new FakeSitePulseClient())->on('GET', '/v1/me', new HttpResponse(200, $body))))->siteId();
                $this->fail('accepted '.json_encode($body));
            } catch (UpstreamException $e) {
                $this->assertSame('sitepulse_no_site', $e->errorCode());
                $this->assertSame(502, $e->httpStatus());
            }
        }
    }

    public function testFindArticleByExternalId(): void
    {
        $client = (new FakeSitePulseClient())->on('GET', '/v1/sites/'.self::SITE.'/articles', new HttpResponse(200, ['articles' => [
            'junk',
            ['id' => 'art_1', 'external_id' => '41', 'title' => 'Other'],
            ['id' => 'bad/id', 'external_id' => '42'],
            ['id' => 'art_2', 'external_id' => 42],
            ['id' => 'art_3', 'external_id' => '42', 'title' => 'Mine', 'url' => 'https://s.example/mine/', 'score' => 77],
        ], 'total' => 4]));
        $service = new SitePulseService($client);
        $this->assertSame(['id' => 'art_3', 'title' => 'Mine', 'url' => 'https://s.example/mine/', 'score' => 77], $service->findArticle(self::SITE, 42, 'mine'));
        $this->assertSame(['q' => 'mine', 'limit' => 200], $client->requests[0]['query']);
        $this->assertSame(['id' => 'art_1', 'title' => 'Other', 'url' => '', 'score' => null], $service->findArticle(self::SITE, 41, 'other'));
        $this->assertNull($service->findArticle(self::SITE, 43, 'x'));
        $before = count($client->requests);
        $this->assertNull($service->findArticle(self::SITE, 0, 'x'));
        $this->assertNull($service->findArticle(self::SITE, 42, ''));
        $this->assertCount($before, $client->requests);
        $this->expectException(InvalidInputException::class);
        $service->findArticle('../x', 42, 'x');
    }

    public function testHistoryAndSuggestions(): void
    {
        $base = '/v1/sites/'.self::SITE.'/articles/art_3';
        $client = (new FakeSitePulseClient())
            ->on('GET', $base.'/history', new HttpResponse(200, ['versions' => [
                ['version' => 1, 'score' => 50, 'analyzed_at' => '2026-11-20T10:00:00Z', 'ruleset_version' => 'content-v1', 'findings' => []],
                ['version' => 2, 'score' => null],
                ['version' => '3'],
                'x',
            ]]))
            ->on('GET', $base.'/links/suggest', new HttpResponse(200, ['suggestions' => [
                ['article_id' => 'a', 'url' => 'https://s.example/a/', 'title' => 'A', 'anchor' => 'docker', 'score' => 3, 'reason' => 'bm25'],
                ['url' => 'https://s.example/b/', 'score' => 1.5],
                ['url' => ''],
                ['title' => 'no url'],
                7,
            ]]));
        $service = new SitePulseService($client);
        $this->assertSame([
            ['version' => 1, 'score' => 50, 'analyzed_at' => '2026-11-20T10:00:00Z', 'ruleset_version' => 'content-v1'],
            ['version' => 2, 'score' => null, 'analyzed_at' => '', 'ruleset_version' => ''],
        ], $service->history(self::SITE, 'art_3'));
        $this->assertSame([
            ['title' => 'A', 'url' => 'https://s.example/a/', 'anchor' => 'docker', 'score' => 3.0, 'reason' => 'bm25'],
            ['title' => '', 'url' => 'https://s.example/b/', 'anchor' => '', 'score' => 1.5, 'reason' => ''],
        ], $service->linkSuggestions(self::SITE, 'art_3'));
        $this->assertSame([], (new SitePulseService((new FakeSitePulseClient())->on('GET', $base.'/history', new HttpResponse(200, ['versions' => null]))))->history(self::SITE, 'art_3'));
        $this->assertSame([], (new SitePulseService((new FakeSitePulseClient())->on('GET', $base.'/links/suggest', new HttpResponse(200, []))))->linkSuggestions(self::SITE, 'art_3'));
        foreach ([['history', self::SITE, 'a b'], ['history', 'x y', 'art_3'], ['linkSuggestions', self::SITE, ''], ['linkSuggestions', '', 'art_3']] as [$method, $site, $article]) {
            try {
                $service->{$method}($site, $article);
                $this->fail('accepted');
            } catch (InvalidInputException $e) {
                $this->assertSame('sitepulse_invalid_id', $e->errorCode());
            }
        }
    }

    public function testInsights(): void
    {
        $client = (new FakeSitePulseClient())
            ->on('GET', '/v1/me', new HttpResponse(200, ['site_id' => self::SITE]))
            ->on('GET', '/v1/sites/'.self::SITE.'/articles', new HttpResponse(200, ['articles' => [['id' => 'art_3', 'external_id' => '42']]]))
            ->on('GET', '/v1/sites/'.self::SITE.'/articles/art_3/history', new HttpResponse(200, ['versions' => [['version' => 1, 'score' => 60]]]))
            ->on('GET', '/v1/sites/'.self::SITE.'/articles/art_3/links/suggest', new HttpResponse(200, ['suggestions' => []]));
        $service = new SitePulseService($client);
        $this->assertSame(['found' => false, 'site_id' => self::SITE], $service->insights(7, 'x'));
        $in = $service->insights(42, 'slug');
        $this->assertTrue($in['found']);
        $this->assertSame(self::SITE, $in['site_id']);
        $this->assertSame('art_3', $in['article']['id'] ?? null);
        $this->assertSame([['version' => 1, 'score' => 60, 'analyzed_at' => '', 'ruleset_version' => '']], $in['history'] ?? null);
        $this->assertSame([], $in['suggestions'] ?? null);
    }

    public function testUnknownEndpointIsNotFound(): void
    {
        try {
            (new SitePulseService(new FakeSitePulseClient()))->siteId();
            $this->fail('no error');
        } catch (UpstreamException $e) {
            $this->assertSame('sitepulse_not_found', $e->errorCode());
            $this->assertSame('Not found on the SitePulse server. unknown endpoint', $e->getMessage());
        }
    }
}
