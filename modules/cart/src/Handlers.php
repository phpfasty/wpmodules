<?php

/**
 * admin-post.php handler `latte_shop_cart` (add / update / remove). Nonce, POST only, rate limit,
 * server-side pricing, safe redirect. Action and nonce names are unchanged from the theme forms.
 *
 * @moduly-request-handler reads the cart form.
 * @package ModulY\Cart
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

// phpcs:disable WordPress.Security.NonceVerification -- every handler calls self::verify() first.

final class Handlers
{
    /** @var (callable(string): bool)|null */
    private static $limiter = null;

    public static function setLimiter(?callable $limiter): void
    {
        self::$limiter = $limiter;
    }

    public static function cart(): void
    {
        self::verify('latte_shop_cart');
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        $id = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        $qty = isset($_POST['qty']) ? absint(wp_unslash($_POST['qty'])) : 1;
        $tier = isset($_POST['license']) ? sanitize_key(wp_unslash($_POST['license'])) : '';
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : (string) $id;
        $notice = 'updated';

        switch ($op) {
            case 'add':
                $notice = Cart::add($id, max(1, min(Cart::MAX_QTY, $qty)), $tier) ? 'added' : 'unavailable';
                break;
            case 'update':
                $qtys = isset($_POST['qtys']) && is_array($_POST['qtys']) ? array_map('absint', wp_unslash($_POST['qtys'])) : [];
                foreach ((array) $qtys as $lineKey => $q) {
                    Cart::set(self::lineKey((string) $lineKey), max(0, min(Cart::MAX_QTY, (int) $q)));
                }
                break;
            case 'remove':
                Cart::set(self::lineKey($key), 0);
                $notice = 'removed';
                break;
            default:
                wp_die(esc_html__('Unknown cart action.', 'moduly'), '', ['response' => 400]);
        }
        Cart::persist();

        $target = $op === 'add' && isset($_POST['buy_now']) ? Pages::url('checkout') : Pages::url('cart');
        if ($op === 'add' && $notice === 'unavailable') {
            $target = wp_get_referer() ?: Pages::shop();
        }
        wp_safe_redirect(add_query_arg('cart', $notice, $target));
        exit;
    }

    /** Cart line key from a form (`5` or `5~extended`); anything else matches no line. */
    private static function lineKey(string $raw): string
    {
        return preg_match('/^\d+(?:~[a-z0-9_-]{1,32})?$/', $raw) === 1 ? $raw : '0';
    }

    /** Nonce + POST + rate limit (shared with the orders module). */
    public static function verify(string $action): void
    {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'post' || !wp_verify_nonce($nonce, $action)) {
            wp_die(
                esc_html__('Your session expired. Please go back and try again.', 'moduly'),
                '',
                ['response' => 403, 'back_link' => true]
            );
        }
        if (self::$limiter !== null && !(self::$limiter)($action)) {
            status_header(429);
            header('Retry-After: 60');
            wp_die(esc_html__('Too many requests. Please wait a minute and try again.', 'moduly'), '', ['response' => 429]);
        }
    }
}
