<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Migration;

/**
 * Read-side rules of ADR-002: aliases (new key first, then old keys) and invalid stored values → default + log,
 * while the stored value stays untouched.
 */
final class FieldValue
{
    /**
     * @param callable(string): mixed $get raw read by key; must return null when the key is absent
     * @param list<string> $keys new key first, then aliases (old keys)
     * @param callable(mixed): bool $valid
     * @param callable(string, array<string, mixed>): void|null $log
     */
    public static function read(callable $get, array $keys, callable $valid, mixed $default, ?callable $log = null): mixed
    {
        foreach ($keys as $key) {
            $raw = $get($key);
            if ($raw === null || $raw === '') {
                continue;
            }
            if ($valid($raw)) {
                return $raw;
            }
            if ($log !== null) {
                $log('invalid stored value, default used', ['key' => $key]);
            }
            return $default;
        }
        return $default;
    }
}
