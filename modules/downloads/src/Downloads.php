<?php

/**
 * Digital delivery: links on the order confirmation (wpfasty_shop_order) and in the "order paid" email,
 * served by admin-post.php?action=moduly_download (GET, signed). Only paid orders, 24 h links, download limit.
 *
 * @moduly-request-handler reads the signed download link parameters.
 * @package ModulY\Downloads
 */

declare(strict_types=1);

namespace PhpFasty\Module\Downloads;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the link is HMAC-signed (Token), no nonce by design.

final class Downloads
{
    public const ACTION = 'moduly_download';
    public const COUNT_META = '_order_downloads';

    private static int $ttl = 24;
    private static int $limit = 5;
    private static string $field = 'download_file';
    private static ?object $registry = null;

    /** @param array<string, mixed> $settings */
    public static function configure(array $settings): void
    {
        self::$ttl = is_int($settings['ttl_hours'] ?? null) ? max(1, $settings['ttl_hours']) : 24;
        self::$limit = is_int($settings['limit'] ?? null) ? max(1, $settings['limit']) : 5;
        self::$field = is_string($settings['file_field'] ?? null) && $settings['file_field'] !== '' ? $settings['file_field'] : 'download_file';
    }

    public static function setRegistry(object $registry): void
    {
        self::$registry = $registry;
    }

    private static function secret(): string
    {
        return wp_salt('auth') . 'moduly_download';
    }

    /** Attachment id of a product's download file (private `file` field, read via the types registry). */
    public static function attachment(int $productId): int
    {
        if (self::$registry === null || !method_exists(self::$registry, 'value')) {
            return 0;
        }
        return (int) self::$registry->value($productId, self::$field);
    }

    /** @return array<int, int> item index => downloads used */
    private static function counts(int $orderId): array
    {
        $raw = get_post_meta($orderId, self::COUNT_META, true);
        return is_array($raw) ? array_map('intval', $raw) : [];
    }

    private static function paid(int $orderId): bool
    {
        return in_array((string) get_post_meta($orderId, '_order_status', true), ['paid', 'processing', 'completed'], true);
    }

    /**
     * Download links of a paid order (fresh 24 h signatures on every call).
     *
     * @return list<array{title: string, url: string, remaining: int, expires: string}>
     */
    public static function links(int $orderId): array
    {
        if (!self::paid($orderId)) {
            return [];
        }
        $expires = time() + self::$ttl * HOUR_IN_SECONDS;
        $counts = self::counts($orderId);
        $out = [];
        foreach ((array) get_post_meta($orderId, '_order_items', true) as $i => $item) {
            if (!is_array($item) || self::attachment((int) ($item['id'] ?? 0)) <= 0) {
                continue;
            }
            $i = (int) $i;
            $url = add_query_arg([
                'action' => self::ACTION,
                'o' => $orderId,
                'i' => $i,
                'e' => $expires,
                's' => Token::sign($orderId, $i, $expires, self::secret()),
            ], admin_url('admin-post.php'));
            $tier = (string) ($item['tier_label'] ?? '');
            $out[] = [
                'title' => (string) ($item['title'] ?? '') . ($tier !== '' ? ' — ' . $tier : ''),
                'url' => $url,
                'remaining' => max(0, self::$limit - ($counts[$i] ?? 0)),
                'expires' => wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $expires),
            ];
        }
        return $out;
    }

    /**
     * Order confirmation view-model: adds `downloads` (list) when the order is paid.
     *
     * @param array<string, mixed>|null $order
     * @return array<string, mixed>|null
     */
    public static function order(?array $order): ?array
    {
        if (!is_array($order) || (int) ($order['id'] ?? 0) <= 0) {
            return $order;
        }
        $order['downloads'] = self::links((int) $order['id']);
        $order['downloads_available'] = true;
        return $order;
    }

    /** Email the links when an order becomes paid. */
    public static function onStatus(int $orderId, string $status, string $old): void
    {
        if (!in_array($status, ['paid', 'completed'], true) || in_array($old, ['paid', 'processing', 'completed'], true)) {
            return;
        }
        $links = self::links($orderId);
        $email = (string) get_post_meta($orderId, '_order_email', true);
        if ($links === [] || !is_email($email)) {
            return;
        }
        $body = sprintf(/* translators: %d: order id */ __('Your order #%d is paid. Download links (valid 24 hours):', 'moduly'), $orderId) . "\n\n";
        foreach ($links as $l) {
            $body .= $l['title'] . "\n" . $l['url'] . "\n\n";
        }
        wp_mail($email, sprintf(/* translators: %d: order id */ __('Downloads for order #%d', 'moduly'), $orderId), $body);
    }

    /** Protect files of private `file` fields when a product is saved. */
    public static function onSave(int $postId): void
    {
        $id = self::attachment($postId);
        if ($id > 0 && current_user_can('edit_post', $postId)) {
            Storage::protect($id);
        }
    }

    /** admin-post.php?action=moduly_download — serve or 403. */
    public static function serve(): void
    {
        $o = isset($_GET['o']) ? absint(wp_unslash($_GET['o'])) : 0;
        $i = isset($_GET['i']) ? absint(wp_unslash($_GET['i'])) : -1;
        $e = isset($_GET['e']) ? absint(wp_unslash($_GET['e'])) : 0;
        $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $valid = Token::valid($o, $i, $e, $s, self::secret(), time())
            && get_post_type($o) === 'shop_order';
        $items = $valid ? (array) get_post_meta($o, '_order_items', true) : [];
        $item = $items[$i] ?? null;
        $path = '';
        if (is_array($item)) {
            $att = self::attachment((int) ($item['id'] ?? 0));
            $path = $att > 0 ? Storage::protect($att) : '';
        }
        $counts = $valid ? self::counts($o) : [];
        $reason = Token::deny([
            'valid' => $valid && is_array($item),
            'paid' => $valid && self::paid($o),
            'count' => $counts[$i] ?? 0,
            'limit' => self::$limit,
            'file' => $path !== '' && Storage::isPrivate($path),
        ]);
        nocache_headers();
        if ($reason !== '') {
            $messages = [
                'invalid' => __('This download link is invalid or has expired. Open your order page for a fresh link.', 'moduly'),
                'unpaid' => __('Downloads open after the order is paid.', 'moduly'),
                'limit' => __('The download limit for this item is reached. Please contact support.', 'moduly'),
                'missing' => __('The file is not available yet. Please contact support.', 'moduly'),
            ];
            wp_die(esc_html($messages[$reason]), esc_html__('Download unavailable', 'moduly'), ['response' => 403, 'back_link' => true]);
        }
        $counts[$i] = ($counts[$i] ?? 0) + 1;
        update_post_meta($o, self::COUNT_META, $counts);
        $name = sanitize_file_name((string) preg_replace('/^\d+-[0-9a-f]{16}-/', '', basename($path)));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        $sendfile = (string) apply_filters('moduly_downloads_sendfile_header', '');
        if (in_array($sendfile, ['X-Sendfile', 'X-Accel-Redirect'], true)) {
            header($sendfile . ': ' . $path);
            exit;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file.
        readfile($path);
        exit;
    }
}
