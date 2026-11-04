<?php

/**
 * Review domain (no WordPress): rating range, aggregate of approved ratings, verified-buyer rule.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Reviews\Domain;

final class RatingPolicy
{
    public const MIN = 1;
    public const MAX = 5;

    /** Order statuses after payment. */
    public const PAID = ['paid', 'processing', 'completed'];

    /** 1–5, anything else is 0 (no rating). */
    public static function rating(mixed $raw): int
    {
        $n = is_numeric($raw) ? (int) $raw : 0;

        return $n >= self::MIN && $n <= self::MAX ? $n : 0;
    }

    /**
     * Average (1 decimal) and count of valid ratings.
     *
     * @param list<int> $ratings
     *
     * @return array{avg: float, count: int}
     */
    public static function aggregate(array $ratings): array
    {
        $ratings = array_values(array_filter($ratings, static fn (int $r): bool => $r >= self::MIN && $r <= self::MAX));
        $count = count($ratings);

        return ['avg' => 0 === $count ? 0.0 : round(array_sum($ratings) / $count, 1), 'count' => $count];
    }

    /**
     * Has one of these orders (paid) the product?
     *
     * @param list<array{status: string, items: array<mixed>}> $orders
     */
    public static function bought(array $orders, int $productId): bool
    {
        foreach ($orders as $o) {
            if (!in_array($o['status'], self::PAID, true)) {
                continue;
            }
            foreach ($o['items'] as $item) {
                if (is_array($item) && is_numeric($item['id'] ?? null) && (int) $item['id'] === $productId) {
                    return true;
                }
            }
        }

        return false;
    }
}
