<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests\Fake;

/** In-memory WordPress options/post meta for migration tests (phpunit only). */

final class FakeWp
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<int, string> post ID → post type */
    public static array $posts = [];
    /** @var array<int, array<string, list<mixed>>> */
    public static array $meta = [];
    /** @var list<array<mixed>> */
    public static array $actions = [];

    public static function reset(): void
    {
        self::$options = self::$posts = self::$meta = self::$actions = [];
    }

    /** @return array<string, mixed> deep copy of all stored data */
    public static function dump(): array
    {
        return ['meta' => self::$meta, 'posts' => self::$posts];
    }
}
