<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

/**
 * Fixed-window rate limit per (bucket, client) in transients / object cache. Service `wp.rate_limiter`.
 */
final class RateLimiter
{
    /** @return bool true when the request is allowed */
    public function hit(string $bucket, int $perMinute = 30): bool
    {
        $key = 'moduly_rl_' . md5($bucket . '|' . AuditLog::ipHash() . '|' . (string) floor(time() / 60));
        $count = (int) get_transient($key);
        if ($count >= $perMinute) {
            return false;
        }
        set_transient($key, $count + 1, 70);
        return true;
    }

    /** Stop the request with 429 + Retry-After. */
    public function deny(): void
    {
        status_header(429);
        header('Retry-After: 60');
        wp_die(esc_html__('Too many requests. Please wait a minute and try again.', 'moduly'), '', ['response' => 429]);
    }
}
