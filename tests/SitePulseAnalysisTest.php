<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests;

use PhpFasty\Module\SitePulse\Domain\AnalyzeRequest;
use PhpFasty\Module\SitePulse\Domain\AnalyzeResult;
use PhpFasty\Module\SitePulse\Domain\ErrorMapper;
use PhpFasty\Module\SitePulse\Domain\Finding;
use PhpFasty\Module\SitePulse\Domain\HttpResponse;
use PhpFasty\Module\SitePulse\Domain\InvalidInputException;
use PhpFasty\Module\SitePulse\Domain\NotConfiguredException;
use PhpFasty\Module\SitePulse\Domain\SitePulseException;
use PhpFasty\Module\SitePulse\Domain\Span;
use PhpFasty\Module\SitePulse\Domain\UpstreamException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SitePulse analysis DTOs (shape of diagd POST /v1/analyze) and the upstream error mapping of the proxy. */
final class SitePulseAnalysisTest extends TestCase
{
    public function testAnalyzeRequestPayload(): void
    {
        $r = AnalyzeRequest::create('<p>Text</p>', '  Title ', ' Desc ', ['go', 'Go', ' k8s ']);
        $this->assertSame(['html' => '<p>Text</p>', 'title' => 'Title', 'description' => 'Desc', 'keywords' => ['go', 'k8s']], $r->toPayload());
        $r = AnalyzeRequest::create('<p>x</p>', str_repeat('я', 301), str_repeat('ё', 1001), null);
        $this->assertSame(300, mb_strlen($r->title));
        $this->assertSame(1000, mb_strlen($r->description));
        $this->assertSame([], $r->keywords);
        $this->assertSame(AnalyzeRequest::MAX_HTML_BYTES, strlen(AnalyzeRequest::create(str_repeat('a', AnalyzeRequest::MAX_HTML_BYTES), '', '', [])->html));
    }

    public function testAnalyzeRequestRejectsEmptyAndHugeContent(): void
    {
        foreach (['' => 'sitepulse_empty_content', " \n\t" => 'sitepulse_empty_content', str_repeat('a', AnalyzeRequest::MAX_HTML_BYTES + 1) => 'sitepulse_content_too_large'] as $html => $code) {
            try {
                AnalyzeRequest::create((string) $html, 't', '', []);
                $this->fail('accepted');
            } catch (InvalidInputException $e) {
                $this->assertSame($code, $e->errorCode());
                $this->assertSame(400, $e->httpStatus());
            }
        }
    }

    public function testSpan(): void
    {
        $this->assertSame(['block_id' => 'p-3', 'start' => 0, 'end' => 0], Span::fromArray(['block_id' => 'p-3', 'start' => 0, 'end' => 0])?->toArray());
        $this->assertSame(['block_id' => 'h2-4', 'start' => 2, 'end' => 9], Span::fromArray(['block_id' => 'h2-4', 'start' => 2, 'end' => 9, 'x' => 1])?->toArray());
        foreach ([null, 'p-1', [], ['block_id' => 'p-1', 'start' => 1], ['block_id' => 'p-1', 'start' => '1', 'end' => 2], ['block_id' => 'p-1', 'start' => 1, 'end' => 2.0],
            ['block_id' => 1, 'start' => 1, 'end' => 2], ['block_id' => '', 'start' => 1, 'end' => 2], ['block_id' => 'p-1', 'start' => -1, 'end' => 2], ['block_id' => 'p-1', 'start' => 3, 'end' => 2]] as $bad) {
            $this->assertNull(Span::fromArray($bad), (string) json_encode($bad));
        }
    }

    public function testFinding(): void
    {
        $f = Finding::fromArray([
            'rule_id' => 'content.keyword.title', 'rule_version' => 1, 'category' => 'keywords', 'severity' => 'high', 'status' => 'fail',
            'title' => 'Ключ не в заголовке', 'recommendation' => 'Добавьте ключ', 'evidence' => ['x' => 1],
            'spans' => [['block_id' => 'title-1', 'start' => 0, 'end' => 5], ['block_id' => '', 'start' => 0, 'end' => 1], 'junk'],
        ]);
        $this->assertSame([
            'rule_id' => 'content.keyword.title', 'category' => 'keywords', 'severity' => 'high', 'status' => 'fail',
            'title' => 'Ключ не в заголовке', 'recommendation' => 'Добавьте ключ',
            'spans' => [['block_id' => 'title-1', 'start' => 0, 'end' => 5]],
        ], $f?->toArray());
        $g = Finding::fromArray(['rule_id' => 'r', 'status' => 'broken', 'title' => 5, 'category' => null, 'severity' => [], 'recommendation' => 1, 'spans' => 'x']);
        $this->assertSame(['rule_id' => 'r', 'category' => '', 'severity' => '', 'status' => 'not-measured', 'title' => '', 'recommendation' => '', 'spans' => []], $g?->toArray());
        foreach (Finding::STATUSES as $status) {
            $this->assertSame($status, Finding::fromArray(['rule_id' => 'r', 'status' => $status])?->status);
        }
        $this->assertNull(Finding::fromArray(['status' => 'fail']));
        $this->assertNull(Finding::fromArray(['rule_id' => '']));
        $this->assertNull(Finding::fromArray(['rule_id' => 3]));
        $this->assertNull(Finding::fromArray('x'));
    }

    public function testResultFromDiagdShape(): void
    {
        $body = [
            'score' => 72.6, 'breakdown' => [['key' => 'keywords', 'weight' => 20]], 'lost' => ['−8 keywords', 5, '−3 water'],
            'caps' => null, 'ruleset_version' => 'content-v1', 'morph' => 'approx',
            'metrics' => ['total' => 72, 'word_count' => 120, 'water' => 14.5, 'nausea_classic' => '3', 'asl' => 9.1],
            'findings' => [
                ['rule_id' => 'a', 'status' => 'pass'],
                ['rule_id' => 'b', 'status' => 'fail'],
                ['nope' => true],
                ['rule_id' => 'c', 'status' => 'warn'],
                ['rule_id' => 'd', 'status' => 'fail'],
            ],
        ];
        $r = AnalyzeResult::fromArray($body);
        $this->assertSame(73, $r->score);
        $this->assertSame(['−8 keywords', '−3 water'], $r->lost);
        $this->assertSame(['word_count' => 120.0, 'water' => 14.5], $r->metrics);
        $this->assertSame('content-v1', $r->rulesetVersion);
        $this->assertSame('approx', $r->morph);
        $this->assertCount(4, $r->findings);
        $groups = $r->grouped();
        $this->assertSame(['fail', 'warn', 'pass', 'not-measured'], array_keys($groups));
        $this->assertSame(['b', 'd'], array_map(static fn (Finding $f): string => $f->ruleId, $groups['fail']));
        $arr = $r->toArray();
        $this->assertSame(['score', 'groups', 'lost', 'ruleset_version', 'morph', 'metrics'], array_keys($arr));
        $this->assertSame(73, $arr['score']);
        $this->assertSame([], $arr['groups']['not-measured']);
        $this->assertSame('c', $arr['groups']['warn'][0]['rule_id']);
        $this->assertSame(['−8 keywords', '−3 water'], $arr['lost']);
        $this->assertSame('content-v1', $arr['ruleset_version']);
        $this->assertSame('approx', $arr['morph']);
        $this->assertSame(['word_count' => 120.0, 'water' => 14.5], $arr['metrics']);
    }

    public function testResultClampsAndDefaults(): void
    {
        $this->assertSame(100, AnalyzeResult::fromArray(['score' => 140])->score);
        $this->assertSame(0, AnalyzeResult::fromArray(['score' => -3])->score);
        $this->assertSame(42, AnalyzeResult::fromArray(['score' => 42.4])->score);
        $this->assertSame(43, AnalyzeResult::fromArray(['score' => 42.5])->score);
        $r = AnalyzeResult::fromArray(['score' => 0, 'findings' => 'x', 'lost' => 'x', 'metrics' => 'x', 'ruleset_version' => 1, 'morph' => null]);
        $this->assertSame([], $r->findings);
        $this->assertSame([], $r->lost);
        $this->assertSame([], $r->metrics);
        $this->assertSame('', $r->rulesetVersion);
        $this->assertSame('', $r->morph);
        foreach ([[], ['score' => '80'], ['score' => null]] as $bad) {
            try {
                AnalyzeResult::fromArray($bad);
                $this->fail('accepted');
            } catch (UpstreamException $e) {
                $this->assertSame('sitepulse_bad_response', $e->errorCode());
                $this->assertSame(502, $e->httpStatus());
            }
        }
    }

    public function testHttpResponse(): void
    {
        $this->assertFalse((new HttpResponse(199, []))->ok());
        $this->assertTrue((new HttpResponse(200, []))->ok());
        $this->assertTrue((new HttpResponse(299, []))->ok());
        $this->assertFalse((new HttpResponse(300, []))->ok());
        $this->assertSame(0, (new HttpResponse(200, []))->retryAfter);
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function statuses(): iterable
    {
        yield '400' => [400, 'sitepulse_bad_request', 422];
        yield '401' => [401, 'sitepulse_auth', 502];
        yield '403' => [403, 'sitepulse_auth', 502];
        yield '404' => [404, 'sitepulse_not_found', 404];
        yield '413' => [413, 'sitepulse_too_large', 413];
        yield '422' => [422, 'sitepulse_bad_request', 422];
        yield '429' => [429, 'sitepulse_rate_limited', 429];
        yield '500' => [500, 'sitepulse_upstream', 502];
        yield '503' => [503, 'sitepulse_upstream', 502];
        yield '302' => [302, 'sitepulse_upstream', 502];
    }

    #[DataProvider('statuses')]
    public function testErrorMapping(int $status, string $code, int $http): void
    {
        $e = ErrorMapper::toException(new HttpResponse($status, [], 7));
        $this->assertSame($code, $e->errorCode());
        $this->assertSame($http, $e->httpStatus());
        $this->assertSame(7, $e->retryAfter());
        $this->assertStringEndsWith('.', $e->getMessage());
    }

    public function testErrorMessages(): void
    {
        $this->assertSame('SitePulse rejected the secret key (check Settings > SitePulse).', ErrorMapper::toException(new HttpResponse(401, ['detail' => '']))->getMessage());
        $this->assertSame(
            'SitePulse could not process the request. html or content is required',
            ErrorMapper::toException(new HttpResponse(400, ['status' => 400, 'code' => 'bad_request', 'detail' => 'html or content is required']))->getMessage()
        );
        $this->assertSame('Not found on the SitePulse server.', ErrorMapper::toException(new HttpResponse(404, []))->getMessage());
        $this->assertSame('The content is too large for SitePulse.', ErrorMapper::toException(new HttpResponse(413, []))->getMessage());
        $this->assertSame('SitePulse rate limit reached; try again later.', ErrorMapper::toException(new HttpResponse(429, []))->getMessage());
        $this->assertSame('The SitePulse server returned an error.', ErrorMapper::toException(new HttpResponse(500, []))->getMessage());
    }

    public function testDetailIsSanitized(): void
    {
        $this->assertSame('', ErrorMapper::detail([]));
        $this->assertSame('', ErrorMapper::detail(['detail' => ['x']]));
        $this->assertSame('bad thing here', ErrorMapper::detail(['detail' => "  <b>bad</b>\n\tthing   here "]));
        $this->assertSame('invalid key [key] for [key] / [key]', ErrorMapper::detail(['detail' => sprintf('invalid key %s_live_0123456789abcdef for %s_live_abcdefgh / st_0123456789', 'sk', 'pk')]));
        $this->assertSame('sk_short ok', ErrorMapper::detail(['detail' => 'sk_short ok']));
        $this->assertSame(str_repeat('ш', ErrorMapper::MAX_DETAIL), ErrorMapper::detail(['detail' => str_repeat('ш', 300)]));
    }

    public function testExceptionHierarchy(): void
    {
        $e = new NotConfiguredException();
        $this->assertInstanceOf(SitePulseException::class, $e);
        $this->assertSame('sitepulse_not_configured', $e->errorCode());
        $this->assertSame(503, $e->httpStatus());
        $this->assertSame(0, $e->retryAfter());
        $this->assertStringContainsString('Settings > SitePulse', $e->getMessage());
        $this->assertSame(500, (new SitePulseException('x', 'y'))->httpStatus());
        $this->assertSame(0, (new SitePulseException('x', 'y'))->retryAfter());
    }
}
