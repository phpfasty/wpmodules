<?php

/**
 * Verified-buyer reviews on top of WordPress comments: a review (comment + `rating` meta 1–5) is accepted only
 * when the author's email has a paid order with the product. The aggregate of approved ratings is written to the
 * product's rating / reviews fields (types registry meta keys).
 * Reads the comment form rating:
 * @moduly-request-handler
 *
 * @package ModulY\Reviews
 */

declare(strict_types=1);

namespace PhpFasty\Module\Reviews;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- the core comment form (wp-comments-post.php) has no nonce for guests; we only read the rating.

final class Reviews
{
    public const META = 'rating';
    public const PAID = ['paid', 'processing', 'completed'];

    private static string $type = 'product';
    private static string $ratingField = 'rating';
    private static string $countField = 'reviews';
    private static ?object $registry = null;

    /** @param array<string, mixed> $settings */
    public static function configure(array $settings): void
    {
        self::$type = is_string($settings['post_type'] ?? null) ? $settings['post_type'] : 'product';
        self::$ratingField = is_string($settings['rating_field'] ?? null) ? $settings['rating_field'] : 'rating';
        self::$countField = is_string($settings['count_field'] ?? null) ? $settings['count_field'] : 'reviews';
    }

    public static function setRegistry(object $registry): void
    {
        self::$registry = $registry;
    }

    public static function rating(mixed $raw): int
    {
        $n = is_numeric($raw) ? (int) $raw : 0;
        return $n >= 1 && $n <= 5 ? $n : 0;
    }

    /**
     * Average (1 decimal) and count of ratings.
     *
     * @param list<int> $ratings
     * @return array{avg: float, count: int}
     */
    public static function aggregate(array $ratings): array
    {
        $ratings = array_values(array_filter($ratings, static fn (int $r): bool => $r >= 1 && $r <= 5));
        $count = count($ratings);
        return ['avg' => $count === 0 ? 0.0 : round(array_sum($ratings) / $count, 1), 'count' => $count];
    }

    /**
     * Did this email buy the product (paid order)?
     *
     * @param list<array{status: string, items: array<mixed>}> $orders
     */
    public static function bought(array $orders, int $productId): bool
    {
        foreach ($orders as $o) {
            if (!in_array($o['status'], self::PAID, true)) {
                continue;
            }
            foreach ($o['items'] as $item) {
                if (is_array($item) && (int) ($item['id'] ?? 0) === $productId) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function verifiedBuyer(string $email, int $productId): bool
    {
        if ($email === '' || $productId <= 0) {
            return false;
        }
        $ids = get_posts([
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => 100,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per submitted review.
            'meta_query' => [['key' => '_order_email', 'value' => $email]],
        ]);
        $orders = array_map(static fn ($id): array => [
            'status' => (string) get_post_meta((int) $id, '_order_status', true),
            'items' => (array) get_post_meta((int) $id, '_order_items', true),
        ], $ids);
        return self::bought($orders, $productId);
    }

    public static function supports(int $postId): bool
    {
        return get_post_type($postId) === self::$type;
    }

    /** Products accept reviews while the module runs (disabled = closed, form hidden). */
    public static function open(bool $open, int $postId): bool
    {
        return self::supports($postId) ? true : $open;
    }

    /**
     * Reject reviews from non-buyers and without a valid rating (before the comment is stored).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function preprocess(array $data): array
    {
        $postId = (int) ($data['comment_post_ID'] ?? 0);
        if (!self::supports($postId) || ($data['comment_type'] ?? 'comment') !== 'comment' || !empty($data['comment_parent'])) {
            return $data;
        }
        $rating = self::rating(isset($_POST['rating']) ? sanitize_text_field(wp_unslash($_POST['rating'])) : '');
        $email = strtolower((string) ($data['comment_author_email'] ?? ''));
        if ($rating === 0) {
            wp_die(esc_html__('Please choose a rating from 1 to 5.', 'moduly'), esc_html__('Review not saved', 'moduly'), ['response' => 400, 'back_link' => true]);
        }
        if (!self::verifiedBuyer($email, $postId)) {
            wp_die(esc_html__('Only buyers with a paid order can review this product. Use the email of your order.', 'moduly'), esc_html__('Review not saved', 'moduly'), ['response' => 403, 'back_link' => true]);
        }
        return $data;
    }

    public static function saved(int $commentId): void
    {
        $c = get_comment($commentId);
        if (!$c instanceof \WP_Comment || !self::supports((int) $c->comment_post_ID)) {
            return;
        }
        $rating = self::rating(isset($_POST['rating']) ? sanitize_text_field(wp_unslash($_POST['rating'])) : '');
        if ($rating > 0) {
            update_comment_meta($commentId, self::META, $rating);
            update_comment_meta($commentId, 'verified', 1);
        }
        self::recount((int) $c->comment_post_ID);
    }

    public static function statusChanged(int|string $commentId): void
    {
        $c = get_comment((int) $commentId);
        if ($c instanceof \WP_Comment && self::supports((int) $c->comment_post_ID)) {
            self::recount((int) $c->comment_post_ID);
        }
    }

    /** Write the aggregate of approved ratings into the product fields. */
    public static function recount(int $postId): void
    {
        $ratings = [];
        foreach (get_comments(['post_id' => $postId, 'status' => 'approve', 'type' => 'comment']) as $c) {
            if ($c instanceof \WP_Comment) {
                $ratings[] = self::rating(get_comment_meta((int) $c->comment_ID, self::META, true));
            }
        }
        $agg = self::aggregate($ratings);
        self::write($postId, self::$ratingField, $agg['avg']);
        self::write($postId, self::$countField, $agg['count']);
    }

    private static function write(int $postId, string $field, int|float $value): void
    {
        $type = self::$registry !== null && method_exists(self::$registry, 'get') ? self::$registry->get(self::$type) : null;
        $fields = is_object($type) && property_exists($type, 'fields') && is_array($type->fields) ? $type->fields : [];
        $def = $fields[$field] ?? null;
        if (is_object($def) && property_exists($def, 'metaKey') && is_string($def->metaKey)) {
            update_post_meta($postId, $def->metaKey, $value);
        }
    }

    /**
     * Product view-model: approved reviews + the form flags.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function product(array $product, int $postId): array
    {
        if (!self::supports($postId)) {
            return $product;
        }
        $list = [];
        foreach (get_comments(['post_id' => $postId, 'status' => 'approve', 'type' => 'comment', 'number' => 20]) as $c) {
            if (!$c instanceof \WP_Comment) {
                continue;
            }
            $list[] = [
                'author' => (string) $c->comment_author,
                'date' => (string) get_comment_date('', $c),
                'text' => (string) $c->comment_content,
                'rating' => self::rating(get_comment_meta((int) $c->comment_ID, self::META, true)),
                'verified' => (bool) get_comment_meta((int) $c->comment_ID, 'verified', true),
            ];
        }
        $product['reviews_available'] = true;
        $product['reviews_list'] = $list;
        $product['review_action'] = site_url('/wp-comments-post.php');
        return $product;
    }
}
