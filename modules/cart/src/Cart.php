<?php

/**
 * Cart stored in an HMAC-signed cookie (no sessions, no DB writes for guests).
 * Prices are never trusted from the client — always recomputed from product meta.
 * Cookie name and format are unchanged from the latte-shop theme (carts survive the move to the module).
 *
 * @moduly-request-handler reads the signed cart cookie.
 * @package ModulY\Cart
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

final class Cart
{
    public const COOKIE = 'latte_shop_cart';
    public const MAX_QTY = 99;
    public const MAX_LINES = 50;

    /**
     * Line key => qty. A key is the product id, or `<id>~<tier>` for a product sold under license tiers
     * (cookie `5:1,9~extended:2.<hmac>`; old `id:qty` cookies stay valid).
     *
     * @var array<int|string, int>|null
     */
    private static ?array $lines = null;

    /** @var array{flat: int, free: int} */
    private static array $shipping = ['flat' => 500, 'free' => 10000];

    private static bool $digitalOnly = false;

    /** @param array<string, mixed> $settings */
    public static function configure(array $settings): void
    {
        self::$shipping = [
            'flat' => is_int($settings['flat_shipping_cents'] ?? null) ? $settings['flat_shipping_cents'] : 500,
            'free' => is_int($settings['free_shipping_cents'] ?? null) ? $settings['free_shipping_cents'] : 10000,
        ];
        self::$digitalOnly = ($settings['digital_only'] ?? false) === true;
    }

    /** Store sells downloads only (setting `digital_only`, filter `wpfasty_shop_digital_only`). */
    public static function digitalOnly(): bool
    {
        return (bool) apply_filters('wpfasty_shop_digital_only', self::$digitalOnly);
    }

    public static function key(int $id, string $tier = ''): string
    {
        return $tier === '' ? (string) $id : $id . '~' . $tier;
    }

    /** @return array{0: int, 1: string} product id, tier */
    public static function parse(int|string $key): array
    {
        $parts = explode('~', (string) $key, 2);
        return [(int) $parts[0], $parts[1] ?? ''];
    }

    /** @return array<int|string, int> */
    public static function lines(): array
    {
        if (self::$lines !== null) {
            return self::$lines;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC-verified in decode().
        self::$lines = self::decode(isset($_COOKIE[self::COOKIE]) ? (string) wp_unslash($_COOKIE[self::COOKIE]) : '');
        return self::$lines;
    }

    public static function add(int $id, int $qty, string $tier = ''): bool
    {
        $tier = Catalog::tier($id, $tier);
        if (!Catalog::isPurchasable($id) || $tier === null) {
            return false; // unknown license tier = tampered form
        }
        $key = self::key($id, $tier);
        $lines = self::lines();
        if (!isset($lines[$key]) && count($lines) >= self::MAX_LINES) {
            return false;
        }
        $lines[$key] = ($lines[$key] ?? 0) + max(1, $qty);
        self::$lines = self::clamp($lines);
        return true;
    }

    public static function set(int|string $key, int $qty): void
    {
        $lines = self::lines();
        $key = is_int($key) || ctype_digit($key) ? (int) $key : $key;
        if ($qty <= 0) {
            unset($lines[$key]);
        } elseif (isset($lines[$key])) {
            $lines[$key] = $qty;
        }
        self::$lines = self::clamp($lines);
    }

    public static function clear(): void
    {
        self::$lines = [];
    }

    public static function count(): int
    {
        return array_sum(self::lines());
    }

    /**
     * Priced cart (server-side prices only).
     *
     * @return array{digital: bool, items: list<array<string, mixed>>, count: int, subtotal: int, shipping: int, total: int}
     */
    public static function summary(): array
    {
        $items = [];
        $subtotal = 0;
        $physical = false;
        foreach (self::lines() as $key => $qty) {
            [$id, $tier] = self::parse($key);
            if (!Catalog::isPurchasable($id) || Catalog::tier($id, $tier) !== $tier) {
                continue; // price is always the server's: a tier the product does not sell is dropped
            }
            $unit = Catalog::price($id, $tier);
            $line = $unit * $qty;
            $subtotal += $line;
            $digital = self::digitalOnly() || Catalog::isDigital($id);
            $physical = $physical || !$digital;
            $thumb = get_post_thumbnail_id($id);
            $items[] = [
                'key' => self::key($id, $tier),
                'tier' => $tier,
                'tier_label' => Catalog::tierLabel($id, $tier),
                'id' => $id,
                'title' => html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => (string) get_permalink($id),
                'qty' => $qty,
                'unit' => $unit,
                'line' => $line,
                'unit_display' => Money::format($unit),
                'line_display' => Money::format($line),
                'image' => $thumb ? (string) wp_get_attachment_image_url((int) $thumb, 'thumbnail') : '',
                'sku' => function_exists('wpfasty_field') ? (string) wpfasty_field($id, 'sku') : '',
                'digital' => $digital,
            ];
        }
        $shipping = $physical ? self::shipping($subtotal) : 0;
        return [
            'digital' => $items !== [] && !$physical,
            'items' => $items,
            'count' => array_sum(array_column($items, 'qty')),
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $subtotal + $shipping,
        ];
    }

    public static function freeShippingCents(): int
    {
        return (int) apply_filters('latte_shop_free_shipping_cents', self::$shipping['free']);
    }

    /** Shipping of physical goods (a cart with downloads only never pays shipping, see summary()). */
    public static function shipping(int $subtotal): int
    {
        if (self::digitalOnly()) {
            return 0;
        }
        $free = self::freeShippingCents();
        $flat = (int) apply_filters('latte_shop_flat_shipping_cents', self::$shipping['flat']);
        return $subtotal === 0 || $subtotal >= $free ? 0 : $flat;
    }

    /** Persist current lines to the signed cookie (call before redirect). */
    public static function persist(): void
    {
        $value = self::encode(self::lines());
        $opts = [
            'expires' => $value === '' ? time() - 3600 : time() + 30 * DAY_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie(self::COOKIE, $value, $opts);
        $_COOKIE[self::COOKIE] = $value;
    }

    /** @param array<int|string, int> $lines */
    public static function encode(array $lines): string
    {
        $lines = self::clamp($lines);
        if ($lines === []) {
            return '';
        }
        $payload = implode(',', array_map(
            static fn (int|string $key, int $qty): string => $key . ':' . $qty,
            array_keys($lines),
            $lines
        ));
        return $payload . '.' . self::sign($payload);
    }

    /** @return array<int|string, int> */
    public static function decode(string $raw): array
    {
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2 || !hash_equals(self::sign($parts[0]), $parts[1])) {
            return [];
        }
        $line = '\d+(?:~[a-z0-9_-]{1,32})?:\d+';
        if (!preg_match('/^' . $line . '(?:,' . $line . ')*$/', $parts[0])) {
            return [];
        }
        $lines = [];
        foreach (explode(',', $parts[0]) as $pair) {
            [$key, $qty] = explode(':', $pair, 2);
            $lines[ctype_digit($key) ? (int) $key : $key] = (int) $qty;
        }
        return self::clamp($lines);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, wp_salt('auth') . self::COOKIE);
    }

    /**
     * @param array<int|string, int> $lines
     * @return array<int|string, int>
     */
    private static function clamp(array $lines): array
    {
        $out = [];
        foreach ($lines as $key => $qty) {
            [$id, $tier] = self::parse($key);
            $qty = min(self::MAX_QTY, (int) $qty);
            if ($id <= 0 || $qty <= 0 || preg_match('/^[a-z0-9_-]{0,32}$/', $tier) !== 1) {
                continue;
            }
            $key = $tier === '' ? $id : self::key($id, $tier);
            $stock = function_exists('get_post_meta') ? Catalog::stock($id) : null;
            $out[$key] = $stock === null ? $qty : min($qty, $stock);
            if ($out[$key] <= 0) {
                unset($out[$key]);
            }
            if (count($out) >= self::MAX_LINES) {
                break;
            }
        }
        return $out;
    }
}
