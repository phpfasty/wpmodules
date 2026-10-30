<?php

/**
 * Shop page URLs (cart / checkout pages by slug, product archive).
 *
 * @package ModulY\Cart
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

final class Pages
{
    public const PAGES = ['cart' => 'Cart', 'checkout' => 'Checkout'];

    public static function url(string $slug): string
    {
        $page = get_page_by_path($slug);
        return $page ? (string) get_permalink($page) : home_url('/' . $slug . '/');
    }

    public static function shop(): string
    {
        return (string) get_post_type_archive_link('product');
    }

    /** Idempotent: create the cart and checkout pages when missing. */
    public static function ensure(): void
    {
        foreach (self::PAGES as $slug => $title) {
            if (!get_page_by_path($slug)) {
                wp_insert_post([
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_name' => $slug,
                    'post_title' => $title,
                ]);
            }
        }
    }
}
