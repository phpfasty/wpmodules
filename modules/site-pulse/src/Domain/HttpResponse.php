<?php

/**
 * Transport-neutral response of the SitePulse client port: status, decoded JSON body and Retry-After seconds.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class HttpResponse
{
    /** @param array<array-key, mixed> $body */
    public function __construct(public readonly int $status, public readonly array $body, public readonly int $retryAfter = 0)
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
