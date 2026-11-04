<?php

/**
 * Order domain (no WordPress): the status lifecycle and the payment method id rule.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Orders\Domain;

final class OrderStatus
{
    public const ALL = ['pending', 'paid', 'processing', 'completed', 'cancelled'];

    /** Statuses after payment (downloads open, verified-buyer reviews allowed). */
    public const PAID = ['paid', 'processing', 'completed'];

    public const PAYMENT_METHODS = ['cod', 'bank'];

    public static function valid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function paid(string $status): bool
    {
        return in_array($status, self::PAID, true);
    }

    /**
     * Keep well-formed payment method ids only.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function paymentMethods(array $ids): array
    {
        return array_values(array_filter($ids, static fn (string $id): bool => 1 === preg_match('/^[a-z0-9_-]{1,32}$/', $id)));
    }
}
