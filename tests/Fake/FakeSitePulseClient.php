<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests\Fake;

use PhpFasty\Module\SitePulse\Domain\ClientInterface;
use PhpFasty\Module\SitePulse\Domain\HttpResponse;

/** Scripted SitePulse server: responses by "METHOD path", every request recorded. */
final class FakeSitePulseClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, query: array<string, string|int>, json: array<string, mixed>|null}> */
    public array $requests = [];

    /** @param array<string, HttpResponse> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function on(string $method, string $path, HttpResponse $response): self
    {
        $this->responses[$method.' '.$path] = $response;

        return $this;
    }

    public function request(string $method, string $path, array $query = [], ?array $json = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'json' => $json];

        return $this->responses[$method.' '.$path] ?? new HttpResponse(404, ['status' => 404, 'code' => 'not_found', 'detail' => 'unknown endpoint']);
    }
}
