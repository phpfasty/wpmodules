<?php

declare(strict_types=1);

// WordPress function stand-ins backed by Fake\FakeWp (phpunit only; excluded from phpstan).

$GLOBALS['wpdb'] = new \PhpFasty\WpModules\Tests\Fake\FakeWpdb();

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return array_key_exists($name, \PhpFasty\WpModules\Tests\Fake\FakeWp::$options) ? \PhpFasty\WpModules\Tests\Fake\FakeWp::$options[$name] : $default;
    }
    function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
    {
        if (array_key_exists($name, \PhpFasty\WpModules\Tests\Fake\FakeWp::$options)) {
            return false;
        }
        \PhpFasty\WpModules\Tests\Fake\FakeWp::$options[$name] = $value;
        return true;
    }
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        \PhpFasty\WpModules\Tests\Fake\FakeWp::$options[$name] = $value;
        return true;
    }
    function delete_option(string $name): bool
    {
        unset(\PhpFasty\WpModules\Tests\Fake\FakeWp::$options[$name]);
        return true;
    }
    function metadata_exists(string $type, int $id, string $key): bool
    {
        return isset(\PhpFasty\WpModules\Tests\Fake\FakeWp::$meta[$id][$key]);
    }
    function get_post_meta(int $id, string $key = '', bool $single = false): mixed
    {
        return \PhpFasty\WpModules\Tests\Fake\FakeWp::$meta[$id][$key][0] ?? '';
    }
    function add_post_meta(int $id, string $key, mixed $value, bool $unique = false): int|false
    {
        if ($unique && isset(\PhpFasty\WpModules\Tests\Fake\FakeWp::$meta[$id][$key])) {
            return false;
        }
        \PhpFasty\WpModules\Tests\Fake\FakeWp::$meta[$id][$key][] = is_scalar($value) ? (string) (is_bool($value) ? (int) $value : $value) : $value;
        return 1;
    }
    function do_action(string $hook, mixed ...$args): void
    {
        \PhpFasty\WpModules\Tests\Fake\FakeWp::$actions[] = [$hook, ...$args];
    }
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
    function add_action(string $hook, mixed $cb, int $prio = 10, int $args = 1): bool
    {
        return true;
    }
    function add_filter(string $hook, mixed $cb, int $prio = 10, int $args = 1): bool
    {
        return true;
    }
}
