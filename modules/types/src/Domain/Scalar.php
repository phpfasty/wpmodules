<?php

/**
 * Narrowing of untyped definition values for the types domain (pure PHP; same rules as the host's Support\Value).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types\Domain;

final class Scalar
{
    public static function str(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '',
            $value instanceof \Stringable => (string) $value,
            default => '',
        };
    }

    public static function int(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_bool($value) => (int) $value,
            is_float($value) => is_finite($value) ? (int) $value : 0,
            is_string($value) && is_numeric($value) => (int) $value,
            default => 0,
        };
    }

    /** @return array<array-key, mixed> */
    public static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        $out = [];
        foreach (self::arr($value) as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
