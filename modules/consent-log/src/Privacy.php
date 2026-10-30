<?php

/**
 * WordPress privacy tools (Tools → Export / Erase Personal Data) for orders and their consent records.
 * Erasing anonymizes (name, phone, address, IP) and keeps the order and the consent proof (legal obligation):
 * nothing is deleted.
 *
 * @package ModulY\ConsentLog
 */

declare(strict_types=1);

namespace PhpFasty\Module\ConsentLog;

final class Privacy
{
    public const PERSONAL = ['name', 'phone', 'address', 'city', 'postcode', 'notes'];

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public static function exporters(array $exporters): array
    {
        $exporters['moduly-orders'] = ['exporter_friendly_name' => __('Orders and consents', 'moduly'), 'callback' => [self::class, 'export']];
        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public static function erasers(array $erasers): array
    {
        $erasers['moduly-orders'] = ['eraser_friendly_name' => __('Orders and consents', 'moduly'), 'callback' => [self::class, 'erase']];
        return $erasers;
    }

    /** @return list<int> */
    private static function orders(string $email, int $page): array
    {
        $ids = get_posts([
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => 50,
            'paged' => $page,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- privacy request, admin-only, paged.
            'meta_query' => [['key' => '_order_email', 'value' => $email]],
        ]);
        return array_map('intval', $ids);
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public static function export(string $email, int $page = 1): array
    {
        $data = [];
        $ids = self::orders($email, $page);
        foreach ($ids as $id) {
            $items = [];
            foreach (array_merge(self::PERSONAL, ['email', 'status', 'total']) as $f) {
                $items[] = ['name' => ucfirst($f), 'value' => (string) get_post_meta($id, '_order_' . $f, true)];
            }
            $log = get_post_meta($id, Consents::META, true);
            if (is_array($log)) {
                foreach ($log as $k => $v) {
                    $items[] = ['name' => 'Consent ' . $k, 'value' => is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v];
                }
            }
            $data[] = ['group_id' => 'moduly-orders', 'group_label' => __('Orders', 'moduly'), 'item_id' => 'order-' . $id, 'data' => $items];
        }
        return ['data' => $data, 'done' => count($ids) < 50];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $ids = self::orders($email, $page);
        foreach ($ids as $id) {
            foreach (self::PERSONAL as $f) {
                if ((string) get_post_meta($id, '_order_' . $f, true) !== '') {
                    update_post_meta($id, '_order_' . $f, self::anonymous($f));
                }
            }
            $log = get_post_meta($id, Consents::META, true);
            if (is_array($log)) {
                update_post_meta($id, Consents::META, self::anonymizeLog($log));
            }
        }
        return [
            'items_removed' => $ids !== [],
            'items_retained' => $ids !== [],
            'messages' => $ids !== [] ? [__('Orders and consent records are kept for accounting and legal proof; personal fields were anonymized.', 'moduly')] : [],
            'done' => count($ids) < 50,
        ];
    }

    public static function anonymous(string $field): string
    {
        return $field === 'name' ? '[deleted]' : '';
    }

    /**
     * @param array<string, mixed> $log
     * @return array<string, mixed>
     */
    public static function anonymizeLog(array $log): array
    {
        $log['ip'] = '';
        $log['anonymized'] = true;
        return $log;
    }
}
