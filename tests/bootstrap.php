<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/stubs-theme.php';

// Minimal WordPress stubs for unit tests of module code (no WP runtime).
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-salt-' . $scheme;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return preg_match('#^https?://#', $url) === 1 ? htmlspecialchars($url, ENT_QUOTES) : '';
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return trim(strip_tags($text));
    }
}
if (!function_exists('get_post_type')) {
    /** Post type by id from $GLOBALS['moduly_test_post_types'] (default "product"). */
    function get_post_type(int $id): string|false
    {
        return $GLOBALS['moduly_test_post_types'][$id] ?? 'product';
    }
}
