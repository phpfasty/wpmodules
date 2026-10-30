<?php

/**
 * Data the shop theme pulls through its filters (the theme stays presentation-only):
 *   wpfasty_shop_state  (array $shop)            → URLs, nonces, cart count, free-shipping amount
 *   wpfasty_shop_cart   (?array $cart)           → priced cart with display strings
 *   wpfasty_shop_product(array $product, int id) → price / sale / stock / purchasable
 *
 * @package ModulY\Cart
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

final class Shop
{
    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    public static function state(array $shop): array
    {
        return array_merge($shop, [
            'enabled' => true,
            'url' => Pages::shop(),
            'cart_url' => Pages::url('cart'),
            'checkout_url' => Pages::url('checkout'),
            'action' => admin_url('admin-post.php'),
            'cart_nonce' => wp_create_nonce('latte_shop_cart'),
            'count' => Cart::count(),
            'free_shipping' => Money::format(Cart::freeShippingCents()),
        ]);
    }

    /**
     * @param array<string, mixed>|null $cart
     * @return array<string, mixed>
     */
    public static function cart(?array $cart): array
    {
        $summary = Cart::summary();
        return $summary + [
            'subtotal_display' => Money::format($summary['subtotal']),
            'shipping_display' => $summary['shipping'] === 0 ? __('Free', 'moduly') : Money::format($summary['shipping']),
            'total_display' => Money::format($summary['total']),
        ];
    }
}
