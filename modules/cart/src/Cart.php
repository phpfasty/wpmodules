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

    /** @var array<int, int>|null product id => qty */
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

    /** @return array<int, int> */
    public static function lines(): array
    {
        if (self::$lines !== null) {
            return self::$lines;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC-verified in decode().
        self::$lines = self::decode(isset($_COOKIE[self::COOKIE]) ? (string) wp_unslash($_COOKIE[self::COOKIE]) : '');
        return self::$lines;
    }

    public static function add(int $id, int $qty): bool
    {
        if (!Catalog::isPurchasable($id)) {
            return false;
        }
        $lines = self::lines();
        if (!isset($lines[$id]) && count($lines) >= self::MAX_LINES) {
            return false;
        }
        $lines[$id] = ($lines[$id] ?? 0) + max(1, $qty);
        self::$lines = self::clamp($lines);
        return true;
    }

    public static function set(int $id, int $qty): void
    {
        $lines = self::lines();
        if ($qty <= 0) {
            unset($lines[$id]);
        } elseif (isset($lines[$id])) {
            $lines[$id] = $qty;
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
        foreach (self::lines() as $id => $qty) {
            if (!Catalog::isPurchasable($id)) {
                continue;
            }
            $unit = Catalog::price($id);
            $line = $unit * $qty;
            $subtotal += $line;
            $digital = self::digitalOnly() || Catalog::isDigital($id);
            $physical = $physical || !$digital;
            $thumb = get_post_thumbnail_id($id);
            $items[] = [
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

    /** @param array<int, int> $lines */
    public static function encode(array $lines): string
    {
        $lines = self::clamp($lines);
        if ($lines === []) {
            return '';
        }
        $payload = implode(',', array_map(
            static fn (int $id, int $qty): string => $id . ':' . $qty,
            array_keys($lines),
            $lines
        ));
        return $payload . '.' . self::sign($payload);
    }

    /** @return array<int, int> */
    public static function decode(string $raw): array
    {
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2 || !hash_equals(self::sign($parts[0]), $parts[1])) {
            return [];
        }
        if (!preg_match('/^\d+:\d+(?:,\d+:\d+)*$/', $parts[0])) {
            return [];
        }
        $lines = [];
        foreach (explode(',', $parts[0]) as $pair) {
            [$id, $qty] = array_map('intval', explode(':', $pair, 2));
            $lines[$id] = $qty;
        }
        return self::clamp($lines);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, wp_salt('auth') . self::COOKIE);
    }

    /**
     * @param array<int, int> $lines
     * @return array<int, int>
     */
    private static function clamp(array $lines): array
    {
        $out = [];
        foreach ($lines as $id => $qty) {
            $id = (int) $id;
            $qty = min(self::MAX_QTY, (int) $qty);
            if ($id <= 0 || $qty <= 0) {
                continue;
            }
            $stock = function_exists('get_post_meta') ? Catalog::stock($id) : null;
            $out[$id] = $stock === null ? $qty : min($qty, $stock);
            if ($out[$id] <= 0) {
                unset($out[$id]);
            }
            if (count($out) >= self::MAX_LINES) {
                break;
            }
        }
        return $out;
    }
}
