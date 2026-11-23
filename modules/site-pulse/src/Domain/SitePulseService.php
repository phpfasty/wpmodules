<?php

/**
 * Use cases of the editor proxy over the client port: stateless analysis, the site of the secret key
 * (`GET /v1/me`), and — when the studio already imported the post — its score history and link suggestions.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class SitePulseService
{
    private const ID = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function analyze(AnalyzeRequest $request): AnalyzeResult
    {
        return AnalyzeResult::fromArray($this->call('POST', '/v1/analyze', [], $request->toPayload()));
    }

    /** Site id bound to the secret key. */
    public function siteId(): string
    {
        $body = $this->call('GET', '/v1/me');
        $site = $body['site_id'] ?? null;
        if (!is_string($site) || 1 !== preg_match(self::ID, $site)) {
            throw new UpstreamException('sitepulse_no_site', 'The key is not a site secret key (sk_live_…).', 502);
        }

        return $site;
    }

    /**
     * The SitePulse article imported from this WordPress post (`external_id` = post ID), found by slug.
     *
     * @return array{id: string, title: string, url: string, score: int|null}|null
     */
    public function findArticle(string $siteId, int $postId, string $slug): ?array
    {
        self::assertId($siteId);
        if ($postId <= 0 || '' === $slug) {
            return null;
        }
        $body = $this->call('GET', '/v1/sites/'.$siteId.'/articles', ['q' => $slug, 'limit' => 200]);
        $external = (string) $postId;
        foreach (is_array($body['articles'] ?? null) ? $body['articles'] : [] as $a) {
            $match = is_array($a) && $external === ($a['external_id'] ?? null);
            if ($match && is_string($a['id'] ?? null) && 1 === preg_match(self::ID, $a['id'])) {
                return [
                    'id' => $a['id'],
                    'title' => is_string($a['title'] ?? null) ? $a['title'] : '',
                    'url' => is_string($a['url'] ?? null) ? $a['url'] : '',
                    'score' => is_int($a['score'] ?? null) ? $a['score'] : null,
                ];
            }
        }

        return null;
    }

    /** @return list<array{version: int, score: int|null, analyzed_at: string, ruleset_version: string}> */
    public function history(string $siteId, string $articleId): array
    {
        self::assertId($siteId);
        self::assertId($articleId);
        $body = $this->call('GET', '/v1/sites/'.$siteId.'/articles/'.$articleId.'/history');
        $out = [];
        foreach (is_array($body['versions'] ?? null) ? $body['versions'] : [] as $v) {
            if (!is_array($v) || !is_int($v['version'] ?? null)) {
                continue;
            }
            $out[] = [
                'version' => $v['version'],
                'score' => is_int($v['score'] ?? null) ? $v['score'] : null,
                'analyzed_at' => is_string($v['analyzed_at'] ?? null) ? $v['analyzed_at'] : '',
                'ruleset_version' => is_string($v['ruleset_version'] ?? null) ? $v['ruleset_version'] : '',
            ];
        }

        return $out;
    }

    /** @return list<array{title: string, url: string, anchor: string, score: float, reason: string}> */
    public function linkSuggestions(string $siteId, string $articleId): array
    {
        self::assertId($siteId);
        self::assertId($articleId);
        $body = $this->call('GET', '/v1/sites/'.$siteId.'/articles/'.$articleId.'/links/suggest');
        $out = [];
        foreach (is_array($body['suggestions'] ?? null) ? $body['suggestions'] : [] as $s) {
            if (!is_array($s) || !is_string($s['url'] ?? null) || '' === $s['url']) {
                continue;
            }
            $out[] = [
                'title' => is_string($s['title'] ?? null) ? $s['title'] : '',
                'url' => $s['url'],
                'anchor' => is_string($s['anchor'] ?? null) ? $s['anchor'] : '',
                'score' => is_int($s['score'] ?? null) || is_float($s['score'] ?? null) ? (float) $s['score'] : 0.0,
                'reason' => is_string($s['reason'] ?? null) ? $s['reason'] : '',
            ];
        }

        return $out;
    }

    /**
     * Everything the sidebar's "In SitePulse" panel shows for a post; `found: false` when the studio has not
     * imported it yet.
     *
     * @return array{found: bool, site_id: string, article?: array{id: string, title: string, url: string, score: int|null},
     *               history?: list<array{version: int, score: int|null, analyzed_at: string, ruleset_version: string}>,
     *               suggestions?: list<array{title: string, url: string, anchor: string, score: float, reason: string}>}
     */
    public function insights(int $postId, string $slug): array
    {
        $site = $this->siteId();
        $article = $this->findArticle($site, $postId, $slug);
        if (null === $article) {
            return ['found' => false, 'site_id' => $site];
        }

        return [
            'found' => true,
            'site_id' => $site,
            'article' => $article,
            'history' => $this->history($site, $article['id']),
            'suggestions' => $this->linkSuggestions($site, $article['id']),
        ];
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $json
     *
     * @return array<array-key, mixed>
     */
    private function call(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $response = $this->client->request($method, $path, $query, $json);
        if (!$response->ok()) {
            throw ErrorMapper::toException($response);
        }

        return $response->body;
    }

    private static function assertId(string $id): void
    {
        if (1 !== preg_match(self::ID, $id)) {
            throw new InvalidInputException('sitepulse_invalid_id', 'Invalid SitePulse id.');
        }
    }
}
