<?php

/**
 * Focus keywords of an article: at most 5 (SitePulse contract, first = primary), trimmed, single-spaced,
 * case-insensitively unique, 100 characters each.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class Keywords
{
    public const MAX = 5;
    public const MAX_LENGTH = 100;

    /** @return list<string> */
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $kw = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $item)), 0, self::MAX_LENGTH);
            $key = mb_strtolower($kw);
            if ('' === $kw || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $kw;
            if (self::MAX === count($out)) {
                break;
            }
        }

        return $out;
    }
}
