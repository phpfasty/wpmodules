<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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
