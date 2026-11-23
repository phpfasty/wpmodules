<?php

/**
 * Maps SitePulse error responses (RFC 9457 problem+json: status, code, detail) to plugin errors. Upstream auth
 * failures become 502 so the editor never confuses them with its own WordPress session.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class ErrorMapper
{
    public const MAX_DETAIL = 200;

    public static function toException(HttpResponse $response): UpstreamException
    {
        $detail = self::detail($response->body);
        [$code, $status, $message] = match (true) {
            401 === $response->status, 403 === $response->status => ['sitepulse_auth', 502, 'SitePulse rejected the secret key (check Settings > SitePulse).'],
            404 === $response->status => ['sitepulse_not_found', 404, 'Not found on the SitePulse server.'],
            400 === $response->status, 422 === $response->status => ['sitepulse_bad_request', 422, 'SitePulse could not process the request.'],
            413 === $response->status => ['sitepulse_too_large', 413, 'The content is too large for SitePulse.'],
            429 === $response->status => ['sitepulse_rate_limited', 429, 'SitePulse rate limit reached; try again later.'],
            default => ['sitepulse_upstream', 502, 'The SitePulse server returned an error.'],
        };

        return new UpstreamException($code, '' === $detail ? $message : $message.' '.$detail, $status, $response->retryAfter);
    }

    /** @param array<array-key, mixed> $body */
    public static function detail(array $body): string
    {
        $detail = is_string($body['detail'] ?? null) ? $body['detail'] : '';
        $detail = trim((string) preg_replace('/\s+/u', ' ', strip_tags($detail)));
        // Never reflect key material even if an upstream message contains it.
        $detail = (string) preg_replace('/\b(sk|pk|st)_[A-Za-z0-9_]{8,}/', '[key]', $detail);

        return mb_substr($detail, 0, self::MAX_DETAIL);
    }
}
