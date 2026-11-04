<?php

/**
 * Narrowing of untyped WordPress values (options, meta, request data, decoded JSON) to scalars and arrays.
 */

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp\Support;

/**
 * Total, side-effect-free conversions: a value of an unexpected type becomes the type's empty value instead of
 * a PHP warning or an "Array" string. Used at every boundary where WordPress hands back `mixed`.
 */
final class Value
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
            is_string($value) && is_numeric(trim($value)) => (int) (float) trim($value),
            default => 0,
        };
    }

    public static function float(mixed $value): float
    {
        return match (true) {
            is_float($value) => $value,
            is_int($value), is_bool($value) => (float) $value,
            is_string($value) && is_numeric(trim($value)) => (float) trim($value),
            default => 0.0,
        };
    }

    public static function bool(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_string($value) => !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true),
            is_int($value) => 0 !== $value,
            is_float($value) => 0.0 !== $value,
            default => false,
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Array with string keys only (integer keys are dropped).
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        return array_map(self::str(...), self::list($value));
    }

    /**
     * @return list<int>
     */
    public static function ints(mixed $value): array
    {
        return array_map(self::int(...), self::list($value));
    }

    /**
     * `$value[$key]` when `$value` is an array, otherwise null.
     */
    public static function at(mixed $value, string|int $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : null;
    }
}
