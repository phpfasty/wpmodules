<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests\Fake;

/** $wpdb stand-in: answers the ID scan of Migration_0002 from FakeWp::$posts. */
final class FakeWpdb
{
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';

    public function prepare(string $sql, mixed ...$args): string
    {
        return json_encode([$sql, $args], JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public function get_col(string $prepared): array // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        [, [$type, $after, $limit]] = json_decode($prepared, true, 512, JSON_THROW_ON_ERROR);
        $ids = array_keys(array_filter(FakeWp::$posts, static fn ($t, $id): bool => $t === $type && $id > $after, ARRAY_FILTER_USE_BOTH));
        sort($ids);
        return array_map('strval', array_slice($ids, 0, (int) $limit));
    }
}
