<?php

/**
 * Cart stored in an HMAC-signed cookie (no sessions, no DB writes for guests).
 * Prices are never trusted from the client — always recomputed from product meta.
 * Cookie name and format are unchanged from the latte-shop theme (carts survive the move to the module).
 *
 * @moduly-request-handler reads the signed cart cookie.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

use PhpFasty\Module\Cart\Domain\CartLine;
use PhpFasty\Module\Cart\Domain\CartSummary;
use PhpFasty\Module\Cart\Domain\LineCodec;
use PhpFasty\Module\Cart\Domain\ShippingPolicy;
use PhpFasty\Moduly\Wp\Support\Hooks;
use PhpFasty\Moduly\Wp\Support\Value;

/**
 * WordPress adapter of the cart (cookie, product catalog, filters) around the pure Domain\LineCodec and
 * Domain\ShippingPolicy.
 */
final class Cart
{
    /** Kept as is (data compatibility): renaming would empty every visitor's cart. */
    public const COOKIE = 'latte_shop_cart';
    public const MAX_QTY = LineCodec::MAX_QTY;
    public const MAX_LINES = LineCodec::MAX_LINES;

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
        return LineCodec::key($id, $tier);
    }

    /** @return array{0: int, 1: string} product id, tier */
    public static function parse(int|string $key): array
    {
        return LineCodec::parse($key);
    }

    /** @return array<int|string, int> */
    public static function lines(): array
    {
        if (null !== self::$lines) {
            return self::$lines;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC-verified in decode().
        self::$lines = self::decode(isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '');

        return self::$lines;
    }

    public static function add(int $id, int $qty, string $tier = ''): bool
    {
        $tier = Catalog::tier($id, $tier);
        if (!Catalog::isPurchasable($id) || null === $tier) {
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
        $lines = [];
        foreach (self::lines() as $key => $qty) {
            [$id, $tier] = self::parse($key);
            if (!Catalog::isPurchasable($id) || Catalog::tier($id, $tier) !== $tier) {
                continue; // price is always the server's: a tier the product does not sell is dropped
            }
            $unit = Catalog::price($id, $tier);
            $thumb = get_post_thumbnail_id($id);
            $lines[] = new CartLine(
                id: $id,
                tier: $tier,
                tierLabel: Catalog::tierLabel($id, $tier),
                title: html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                url: (string) get_permalink($id),
                qty: $qty,
                unit: $unit,
                unitDisplay: Money::format($unit),
                lineDisplay: Money::format($unit * $qty),
                image: $thumb ? (string) wp_get_attachment_image_url((int) $thumb, 'thumbnail') : '',
                sku: function_exists('wpfasty_field') ? Value::str(wpfasty_field($id, 'sku')) : '',
                digital: self::digitalOnly() || Catalog::isDigital($id),
            );
        }

        return CartSummary::of($lines, self::policy())->toArray();
    }

    public static function freeShippingCents(): int
    {
        return Value::int(Hooks::filter('moduly_shop_free_shipping_cents', 'latte_shop_free_shipping_cents', self::$shipping['free']));
    }

    /** Shipping of physical goods (a cart with downloads only never pays shipping, see summary()). */
    public static function shipping(int $subtotal): int
    {
        return self::policy()->cost($subtotal);
    }

    public static function policy(): ShippingPolicy
    {
        return new ShippingPolicy(
            Value::int(Hooks::filter('moduly_shop_flat_shipping_cents', 'latte_shop_flat_shipping_cents', self::$shipping['flat'])),
            self::freeShippingCents(),
            self::digitalOnly(),
        );
    }

    /** Persist current lines to the signed cookie (call before redirect). */
    public static function persist(): void
    {
        $value = self::encode(self::lines());
        $opts = [
            'expires' => '' === $value ? time() - 3600 : time() + 30 * DAY_IN_SECONDS,
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
        return self::codec()->encode($lines);
    }

    /** @return array<int|string, int> */
    public static function decode(string $raw): array
    {
        return self::codec()->decode($raw);
    }

    private static function codec(): LineCodec
    {
        return new LineCodec(
            wp_salt('auth').self::COOKIE,
            static fn (int $id): ?int => function_exists('get_post_meta') ? Catalog::stock($id) : null,
        );
    }

    /**
     * @param array<int|string, int> $lines
     *
     * @return array<int|string, int>
     */
    private static function clamp(array $lines): array
    {
        return self::codec()->clamp($lines);
    }
}
