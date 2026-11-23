<?php

/**
 * Port: authenticated calls to the configured SitePulse server (adapter: WpHttpClient with wp_remote_request).
 * Implementations send `Authorization: Bearer sk_…` server-side only and throw UpstreamException on transport errors.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

interface ClientInterface
{
    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $json
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): HttpResponse;
}
