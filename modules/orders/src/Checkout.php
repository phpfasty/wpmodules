<?php

/**
 * admin-post.php handler `moduly_checkout` + checkout / order data for the theme:
 *   wpfasty_shop_state    → checkout nonce
 *   wpfasty_shop_checkout (array $checkout)            → errors + kept values from the last attempt
 *   wpfasty_shop_order    (?array $order, int id, key) → order confirmation
 *
 * @moduly-request-handler reads the checkout form and redirect parameters.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Orders;

use PhpFasty\Module\Cart\CartApi;
use PhpFasty\Module\Orders\Domain\CheckoutPolicy;
use PhpFasty\Moduly\Wp\Support\Value;

// phpcs:disable WordPress.Security.NonceVerification -- the handler verifies first; GET reads are display-only.

final class Checkout
{
    public const ACTION = 'moduly_checkout';

    /** @var array<string, array<array-key, mixed>> form input restored in this request, by token (read-once) */
    private static array $restored = [];

    private static CartApi $cart;

    public static function setCart(CartApi $cart): void
    {
        self::$cart = $cart;
    }

    public static function handle(): void
    {
        self::$cart->verify(self::ACTION);
        $summary = self::$cart->summary();
        $back = self::$cart->pageUrl('checkout');
        if ([] === $summary['items']) {
            wp_safe_redirect(add_query_arg('cart', 'empty', self::$cart->pageUrl('cart')));
            exit;
        }

        $c = [];
        foreach (CheckoutPolicy::FIELDS as $f) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field just below.
            $raw = isset($_POST[$f]) && is_string($_POST[$f]) ? Value::str(wp_unslash($_POST[$f])) : '';
            $c[$f] = match ($f) {
                'email' => sanitize_email($raw),
                'notes', 'address' => sanitize_textarea_field($raw),
                'payment' => sanitize_key($raw),
                default => sanitize_text_field($raw),
            };
        }

        $errors = CheckoutPolicy::validate($c, self::required(!empty($summary['digital'])), Orders::paymentMethods());
        // WordPress email rules are stricter than FILTER_VALIDATE_EMAIL
        if ('' !== $c['email'] && !in_array('email', $errors, true) && !is_email($c['email'])) {
            $errors[] = 'email';
        }
        // Other modules (consent-log) add their own checks; unknown error codes are dropped on display.
        $errors = array_values(array_map(Value::str(...), (array) apply_filters('wpfasty_shop_checkout_errors', $errors, $c)));
        if ([] !== $errors) {
            // Keep the form input across the redirect, keyed by a random token: read once (deleted on first
            // display), expires after 2 minutes if never displayed. The payment choice is not personal data.
            $token = strtolower(wp_generate_password(20, false));
            set_transient('moduly_co_'.$token, $c, 2 * MINUTE_IN_SECONDS);
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
        $fields = (array) apply_filters('wpfasty_shop_checkout_required', CheckoutPolicy::defaultRequired($digital), $digital);

        return CheckoutPolicy::knownFields(array_values(array_map(Value::str(...), $fields)));
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
            ? CheckoutPolicy::displayable(explode(',', sanitize_text_field(wp_unslash($_GET['co_errors']))))
            : [];
        $token = isset($_GET['co']) && is_string($_GET['co']) ? sanitize_key(wp_unslash($_GET['co'])) : '';
        if ('' !== $token && !array_key_exists($token, self::$restored)) {
            $old = get_transient('moduly_co_'.$token);
            delete_transient('moduly_co_'.$token);
            self::$restored[$token] = is_array($old) ? $old : [];
        }
        if ('' !== $token && [] !== self::$restored[$token]) {
            $checkout['values'] = self::$restored[$token];
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
