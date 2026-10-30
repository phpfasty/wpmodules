<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Signature;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use JsonException;

/**
 * Canonical JSON: object keys sorted recursively, no whitespace, unescaped slashes/unicode.
 */
final class Canonical
{
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Value cannot be canonicalized: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::sort($v);
        }
        return (object) $out;
    }
}
