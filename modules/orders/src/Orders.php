<?php

/**
 * Orders: private `shop_order` posts created from the cart. Post type, meta keys (`_order_*`) and amounts in
 * integer minor units are unchanged from the latte-shop theme, so existing orders keep working.
 *
 * @moduly-request-handler saves the admin status box.
 * @package ModulY\Orders
 */

declare(strict_types=1);

namespace PhpFasty\Module\Orders;

use PhpFasty\Module\Cart\CartApi;

final class Orders
{
    public const STATUSES = ['pending', 'processing', 'completed', 'cancelled'];
    public const PAYMENT_METHODS = ['cod', 'bank'];

    private static CartApi $cart;

    public static function setCart(CartApi $cart): void
    {
        self::$cart = $cart;
    }

    /** Register the post type unless the active theme / types layer already declared it (same arguments). */
    public static function registerType(): void
    {
        if (post_type_exists('shop_order')) {
            return;
        }
        register_post_type('shop_order', [
            'labels' => [
                'name' => __('Orders', 'moduly'),
                'singular_name' => __('Order', 'moduly'),
                'edit_item' => __('Order', 'moduly'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => post_type_exists('product') ? 'edit.php?post_type=product' : true,
            'supports' => ['title'],
            'capability_type' => 'post',
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);
    }

    public static function registerAdmin(): void
    {
        add_filter('manage_shop_order_posts_columns', static fn (array $c): array => [
            'cb' => $c['cb'] ?? '',
            'title' => __('Order', 'moduly'),
            'customer' => __('Customer', 'moduly'),
            'total' => __('Total', 'moduly'),
            'order_status' => __('Status', 'moduly'),
            'date' => $c['date'] ?? __('Date', 'moduly'),
        ]);
        add_action('manage_shop_order_posts_custom_column', static function (string $col, int $id): void {
            match ($col) {
                'customer' => print(esc_html(
                    (string) get_post_meta($id, '_order_name', true)
                    . ' <' . (string) get_post_meta($id, '_order_email', true) . '>'
                )),
                'total' => print(esc_html(self::$cart->format((int) get_post_meta($id, '_order_total', true)))),
                'order_status' => print(esc_html(ucfirst((string) get_post_meta($id, '_order_status', true)))),
                default => null,
            };
        }, 10, 2);
        add_action('add_meta_boxes_shop_order', [self::class, 'metaBoxes']);
        add_action('save_post_shop_order', [self::class, 'saveStatus']);
    }

    /**
     * @param array<string, string> $customer sanitized fields
     * @param array{digital?: bool, items: list<array<string, mixed>>, subtotal: int, shipping: int, total: int} $summary
     * @return array{id: int, key: string}|null
     */
    public static function create(array $customer, array $summary): ?array
    {
        $key = wp_generate_password(24, false);
        $id = wp_insert_post([
            'post_type' => 'shop_order',
            'post_status' => 'private',
            'post_title' => sprintf('#%s — %s', gmdate('ymd-His'), $customer['name']),
        ], true);
        if (is_wp_error($id)) {
            return null;
        }
        $id = (int) $id;
        wp_update_post(['ID' => $id, 'post_title' => sprintf('#%d — %s', $id, $customer['name'])]);
        foreach ($customer as $field => $value) {
            update_post_meta($id, "_order_{$field}", $value);
        }
        $items = array_map(static fn (array $i): array => [
            'id' => (int) $i['id'], 'title' => (string) $i['title'], 'sku' => (string) $i['sku'],
            'qty' => (int) $i['qty'], 'unit' => (int) $i['unit'], 'line' => (int) $i['line'],
            'tier' => (string) ($i['tier'] ?? ''), 'tier_label' => (string) ($i['tier_label'] ?? ''),
            'digital' => (bool) ($i['digital'] ?? false),
        ], $summary['items']);
        update_post_meta($id, '_order_items', $items);
        update_post_meta($id, '_order_subtotal', $summary['subtotal']);
        update_post_meta($id, '_order_shipping', $summary['shipping']);
        update_post_meta($id, '_order_total', $summary['total']);
        update_post_meta($id, '_order_currency', self::$cart->currency());
        update_post_meta($id, '_order_status', 'pending');
        update_post_meta($id, '_order_key', $key);

        foreach ($items as $item) {
            $stock = self::$cart->stock($item['id']);
            if ($stock !== null) {
                self::$cart->setStock($item['id'], $stock - $item['qty']);
            }
        }

        do_action('latte_shop_order_created', $id);
        wp_mail(
            (string) get_option('admin_email'),
            sprintf(/* translators: %d order id */ __('New order #%d', 'moduly'), $id),
            sprintf("%s\n%s\n%s", $customer['name'], $customer['email'], self::$cart->format($summary['total']))
        );
        return ['id' => $id, 'key' => $key];
    }

    /**
     * Order view-model if id + key match (`wpfasty_shop_order`).
     *
     * @return array<string, mixed>|null
     */
    public static function find(mixed $order, int $id, string $key): ?array
    {
        if ($id <= 0 || $key === '') {
            return is_array($order) ? $order : null;
        }
        $post = get_post($id);
        $stored = (string) get_post_meta($id, '_order_key', true);
        $isOrder = $post instanceof \WP_Post && $post->post_type === 'shop_order';
        if (!$isOrder || $stored === '' || !hash_equals($stored, $key)) {
            return null;
        }
        $items = (array) get_post_meta($id, '_order_items', true);
        return [
            'id' => $id,
            'name' => (string) get_post_meta($id, '_order_name', true),
            'email' => (string) get_post_meta($id, '_order_email', true),
            'payment' => (string) get_post_meta($id, '_order_payment', true),
            'status' => (string) get_post_meta($id, '_order_status', true),
            'items' => array_map(
                static fn (array $i): array => $i + ['line_display' => self::$cart->format((int) $i['line'])],
                array_filter($items, 'is_array')
            ),
            'subtotal_display' => self::$cart->format((int) get_post_meta($id, '_order_subtotal', true)),
            'shipping_display' => self::$cart->format((int) get_post_meta($id, '_order_shipping', true)),
            'total_display' => self::$cart->format((int) get_post_meta($id, '_order_total', true)),
        ];
    }

    public static function metaBoxes(): void
    {
        add_meta_box('latte-shop-order', __('Order details', 'moduly'), static function (\WP_Post $post): void {
            $id = $post->ID;
            echo '<table class="widefat striped"><thead><tr>'
                . '<th>' . esc_html__('Item', 'moduly') . '</th>'
                . '<th>' . esc_html__('Qty', 'moduly') . '</th>'
                . '<th>' . esc_html__('Total', 'moduly') . '</th>'
                . '</tr></thead><tbody>';
            foreach ((array) get_post_meta($id, '_order_items', true) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $tier = (string) ($item['tier_label'] ?? '');
                printf(
                    '<tr><td>%s</td><td>%d</td><td>%s</td></tr>',
                    esc_html((string) ($item['title'] ?? '') . ($tier !== '' ? ' — ' . $tier : '')),
                    (int) ($item['qty'] ?? 0),
                    esc_html(self::$cart->format((int) ($item['line'] ?? 0)))
                );
            }
            echo '</tbody></table><p>';
            foreach (['name', 'email', 'phone', 'address', 'city', 'postcode', 'country', 'payment', 'notes'] as $f) {
                $v = (string) get_post_meta($id, "_order_{$f}", true);
                if ($v !== '') {
                    printf('<strong>%s:</strong> %s<br>', esc_html(ucfirst($f)), esc_html($v));
                }
            }
            printf(
                '<strong>%s:</strong> %s</p>',
                esc_html__('Total', 'moduly'),
                esc_html(self::$cart->format((int) get_post_meta($id, '_order_total', true)))
            );
        }, 'shop_order', 'normal', 'high');

        add_meta_box('latte-shop-order-status', __('Status', 'moduly'), static function (\WP_Post $post): void {
            wp_nonce_field('latte_shop_order_status', 'latte_shop_order_nonce');
            $current = (string) get_post_meta($post->ID, '_order_status', true);
            echo '<select name="order_status" class="widefat">';
            foreach (self::STATUSES as $s) {
                printf(
                    '<option value="%1$s"%2$s>%3$s</option>',
                    esc_attr($s),
                    selected($current, $s, false),
                    esc_html(ucfirst($s))
                );
            }
            echo '</select>';
        }, 'shop_order', 'side');
    }

    public static function saveStatus(int $postId): void
    {
        $nonce = isset($_POST['latte_shop_order_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['latte_shop_order_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'latte_shop_order_status') || !current_user_can('edit_post', $postId)) {
            return;
        }
        $status = isset($_POST['order_status']) ? sanitize_key(wp_unslash($_POST['order_status'])) : '';
        if (in_array($status, self::STATUSES, true)) {
            update_post_meta($postId, '_order_status', $status);
        }
    }
}
