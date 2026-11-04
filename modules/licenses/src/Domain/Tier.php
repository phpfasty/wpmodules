<?php

/**
 * License domain (no WordPress): tier ids and the normalised tier rows of a product (labels are the adapter's).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Licenses\Domain;

final class Tier
{
    public const KNOWN = ['regular', 'extended'];

    public static function validId(string $id): bool
    {
        return 1 === preg_match('/^[a-z0-9_-]{1,32}$/', $id);
    }

    /**
     * Product rows (`tier` or `id`, `label`, `price`) → valid tiers; the first one is the default.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<array{id: string, label: string, price: string, default: bool}>
     */
    public static function normalize(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = self::text($row['tier'] ?? $row['id'] ?? '');
            if (!self::validId($id)) {
                continue;
            }
            $label = self::text($row['label'] ?? '');
            $out[] = ['id' => $id, 'label' => '' === $label ? $id : $label, 'price' => self::text($row['price'] ?? ''), 'default' => [] === $out];
        }

        return $out;
    }

    private static function text(mixed $v): string
    {
        return is_string($v) || is_int($v) || is_float($v) ? (string) $v : '';
    }
}
