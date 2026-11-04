<?php

/**
 * admin-post.php handler `moduly_checkout` (deprecated alias `latte_shop_checkout`) + checkout / order data for the theme:
 *   wpfasty_shop_state    → checkout nonce
 *   wpfasty_shop_checkout (array $checkout)            → errors + kept values from the last attempt
 *   wpfasty_shop_order    (?array $order, int id, key) → order confirmation
 *
 * @moduly-request-handler reads the checkout form and redirect parameters.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Orders;

use PhpFasty\Module\Cart\CartApi;
use PhpFasty\Moduly\Wp\Support\Value;

// phpcs:disable WordPress.Security.NonceVerification -- the handler verifies first; GET reads are display-only.

final class Checkout
{
    public const ACTION = 'moduly_checkout';
    /** @deprecated 0.2.0 use self::ACTION */
    public const DEPRECATED_ACTION = 'latte_shop_checkout';

    private const FIELDS = ['name', 'email', 'phone', 'address', 'city', 'postcode', 'country', 'notes', 'payment'];
    private const REQUIRED = ['name', 'email', 'address', 'city', 'postcode', 'country'];
    private const ERRORS = ['name', 'email', 'address', 'city', 'postcode', 'country', 'payment', 'server'];
    /** Error codes other modules may add (`wpfasty_shop_checkout_errors`), e.g. consent_offer. */
    private const EXTRA_ERROR = '/^[a-z][a-z0-9_]{1,31}$/';

    private static CartApi $cart;

    public static function setCart(CartApi $cart): void
    {
        self::$cart = $cart;
    }

    public static function handle(): void
    {
        self::$cart->verify(self::ACTION, self::DEPRECATED_ACTION);
        $summary = self::$cart->summary();
        $back = self::$cart->pageUrl('checkout');
        if ([] === $summary['items']) {
            wp_safe_redirect(add_query_arg('cart', 'empty', self::$cart->pageUrl('cart')));
            exit;
        }

        $c = [];
        foreach (self::FIELDS as $f) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field just below.
            $raw = isset($_POST[$f]) && is_string($_POST[$f]) ? Value::str(wp_unslash($_POST[$f])) : '';
            $c[$f] = match ($f) {
                'email' => sanitize_email($raw),
                'notes', 'address' => sanitize_textarea_field($raw),
                'payment' => sanitize_key($raw),
                default => sanitize_text_field($raw),
            };
        }

        $errors = [];
        foreach (self::required(!empty($summary['digital'])) as $required) {
            if ('' === $c[$required]) {
                $errors[] = $required;
            }
        }
        if ('' !== $c['email'] && !is_email($c['email'])) {
            $errors[] = 'email';
        }
        if (!in_array($c['payment'], Orders::paymentMethods(), true)) {
            $errors[] = 'payment';
        }
        // Other modules (consent-log) add their own checks; unknown error codes are dropped on display.
        $errors = array_values(array_map(Value::str(...), (array) apply_filters('wpfasty_shop_checkout_errors', $errors, $c)));
        if ([] !== $errors) {
            // Keep non-sensitive input across the redirect for 10 minutes, keyed by a random token.
            $token = strtolower(wp_generate_password(16, false));
            set_transient('moduly_co_'.$token, $c, 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['co_errors' => implode(',', array_unique($errors)), 'co' => $token], $back));
            exit;
        }

        $order = Orders::create($c, $summary);
        if (null === $order) {
            wp_safe_redirect(add_query_arg('co_errors', 'server', $back));
            exit;
        }
        self::$cart->reset();
        wp_safe_redirect(add_query_arg(['order' => $order['id'], 'key' => $order['key']], $back));
        exit;
    }

    /**
     * Required checkout fields: a downloads-only cart needs no postal address (filter `wpfasty_shop_checkout_required`).
     *
     * @return list<string>
     */
    public static function required(bool $digital): array
    {
        $fields = $digital ? ['name', 'email'] : self::REQUIRED;
        $fields = (array) apply_filters('wpfasty_shop_checkout_required', $fields, $digital);

        return array_values(array_intersect(array_map(Value::str(...), $fields), self::FIELDS));
    }

    /**
     * @param array<string, mixed> $shop
     *
     * @return array<string, mixed>
     */
    public static function state(array $shop): array
    {
        $shop['checkout_nonce'] = wp_create_nonce(self::ACTION);
        $shop['checkout_action'] = self::ACTION;
        $shop['orders'] = true;
        $shop['checkout_available'] = true;

        return $shop;
    }

    /**
     * @param array<string, mixed> $checkout
     *
     * @return array<string, mixed>
     */
    public static function checkout(array $checkout): array
    {
        $checkout['errors'] = isset($_GET['co_errors']) && is_string($_GET['co_errors'])
            ? array_values(array_filter(
                explode(',', sanitize_text_field(wp_unslash($_GET['co_errors']))),
                static fn (string $e): bool => in_array($e, self::ERRORS, true) || 1 === preg_match(self::EXTRA_ERROR, $e)
            ))
            : [];
        $token = isset($_GET['co']) && is_string($_GET['co']) ? sanitize_key(wp_unslash($_GET['co'])) : '';
        $old = '' !== $token ? get_transient('moduly_co_'.$token) : false;
        if (is_array($old)) {
            $checkout['values'] = $old;
        }

        return $checkout;
    }

    /**
     * @param array<string, mixed>|null $order
     *
     * @return array<string, mixed>|null
     */
    public static function order(?array $order): ?array
    {
        $id = isset($_GET['order']) && is_string($_GET['order']) ? absint(wp_unslash($_GET['order'])) : 0;
        $key = isset($_GET['key']) && is_string($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        return Orders::find($order, $id, $key);
    }
}
