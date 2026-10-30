<?php

/**
 * Signed download links: HMAC-SHA256 over order id, item index and expiry (no state needed to check a link).
 *
 * @package ModulY\Downloads
 */

declare(strict_types=1);

namespace PhpFasty\Module\Downloads;

final class Token
{
    public static function sign(int $order, int $item, int $expires, string $secret): string
    {
        return hash_hmac('sha256', $order . '|' . $item . '|' . $expires, $secret);
    }

    /** Valid signature and not expired. */
    public static function valid(int $order, int $item, int $expires, string $sig, string $secret, int $now): bool
    {
        return $order > 0 && $item >= 0 && $expires >= $now
            && hash_equals(self::sign($order, $item, $expires, $secret), $sig);
    }

    /**
     * Gate for one request: '' = serve; otherwise the reason (all answered 403, never the file).
     *
     * @param array{valid: bool, paid: bool, count: int, limit: int, file: bool} $f
     */
    public static function deny(array $f): string
    {
        return match (true) {
            !$f['valid'] => 'invalid',
            !$f['paid'] => 'unpaid',
            $f['count'] >= $f['limit'] => 'limit',
            !$f['file'] => 'missing',
            default => '',
        };
    }
}
