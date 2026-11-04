<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests\Fake;

use PhpFasty\Moduly\Wp\Support\Value;

/** $wpdb stand-in: answers the ID scan of Migration_0002 from FakeWp::$posts. */
final class FakeWpdb extends \wpdb
{
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';

    /**
     * @param string $query
     */
    public function prepare($query, ...$args): string
    {
        return json_encode([$query, $args], JSON_THROW_ON_ERROR);
    }

    /**
     * @param string|null $query
     * @param int         $x
     *
     * @return list<string>
     */
    public function get_col($query = null, $x = 0): array // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        $args = Value::list(Value::at(json_decode((string) $query, true, 512, JSON_THROW_ON_ERROR), 1));
        [, $type, $after, $limit] = $args + [null, null, 0, 0]; // [table, post_type, after id, limit]
        $ids = array_keys(array_filter(FakeWp::$posts, static fn ($t, $id): bool => $t === $type && $id > $after, ARRAY_FILTER_USE_BOTH));
        sort($ids);

        return array_map(Value::str(...), array_slice($ids, 0, Value::int($limit)));
    }
}
