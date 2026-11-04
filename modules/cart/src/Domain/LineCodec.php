<?php

/**
 * Cart lines <-> signed cookie value. Pure: no WordPress, the secret and the stock lookup are injected.
 *
 * Format `5:1,9~extended:2.<hmac-sha256>` (line key = product id, or `<id>~<tier>`; old `id:qty` values stay valid).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart\Domain;

final class LineCodec
{
    public const MAX_QTY = 99;
    public const MAX_LINES = 50;
    private const LINE = '\d+(?:~[a-z0-9_-]{1,32})?:\d+';

    /**
     * @param string                    $secret HMAC key (site salt + cookie name)
     * @param \Closure(int): (int|null) $stock  units in stock of a product, null = unlimited
     */
    public function __construct(private readonly string $secret, private readonly \Closure $stock)
    {
    }

    public static function key(int $id, string $tier = ''): string
    {
        return '' === $tier ? (string) $id : $id.'~'.$tier;
    }

    /** @return array{0: int, 1: string} product id, tier */
    public static function parse(int|string $key): array
    {
        $parts = explode('~', (string) $key, 2);

        return [(int) $parts[0], $parts[1] ?? ''];
    }

    /** @param array<int|string, int> $lines */
    public function encode(array $lines): string
    {
        $lines = $this->clamp($lines);
        if ([] === $lines) {
            return '';
        }
        $payload = implode(',', array_map(
            static fn (int|string $key, int $qty): string => $key.':'.$qty,
            array_keys($lines),
            $lines
        ));

        return $payload.'.'.$this->sign($payload);
    }

    /** @return array<int|string, int> */
    public function decode(string $raw): array
    {
        $parts = explode('.', $raw, 2);
        if (2 !== count($parts) || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return [];
        }
        if (1 !== preg_match('/^'.self::LINE.'(?:,'.self::LINE.')*$/', $parts[0])) {
            return [];
        }
        $lines = [];
        foreach (explode(',', $parts[0]) as $pair) {
            [$key, $qty] = explode(':', $pair, 2);
            $lines[ctype_digit($key) ? (int) $key : $key] = (int) $qty;
        }

        return $this->clamp($lines);
    }

    /**
     * Drops invalid lines, caps quantities (MAX_QTY, stock) and the number of lines (MAX_LINES).
     *
     * @param array<int|string, int> $lines
     *
     * @return array<int|string, int>
     */
    public function clamp(array $lines): array
    {
        $out = [];
        foreach ($lines as $key => $qty) {
            [$id, $tier] = self::parse($key);
            $qty = min(self::MAX_QTY, $qty);
            if ($id <= 0 || $qty <= 0 || 1 !== preg_match('/^[a-z0-9_-]{0,32}$/', $tier)) {
                continue;
            }
            $key = '' === $tier ? $id : self::key($id, $tier);
            $stock = ($this->stock)($id);
            $qty = null === $stock ? $qty : min($qty, $stock);
            if ($qty <= 0) {
                continue;
            }
            $out[$key] = $qty;
            if (count($out) >= self::MAX_LINES) {
                break;
            }
        }

        return $out;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
