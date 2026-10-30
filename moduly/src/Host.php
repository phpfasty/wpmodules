<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Contract\HostInterface;

/**
 * WordPress implementation of the core host port.
 */
final class Host implements HostInterface
{
    /** @var array<string, array<string, mixed>> module id => settings schema */
    private array $schemas = [];

    public function name(): string
    {
        return 'wp';
    }

    public function version(): string
    {
        return (string) get_bloginfo('version');
    }

    public function on(string $type, string $hook, callable $callback, int $priority = 10, int $args = 1): void
    {
        if ($type === 'filter') {
            add_filter($hook, $callback, $priority, $args);
        } else {
            add_action($hook, $callback, $priority, $args);
        }
    }

    /** @param array<string, mixed> $schema */
    public function setSchema(string $moduleId, array $schema): void
    {
        $this->schemas[$moduleId] = $schema;
    }

    public function settings(string $moduleId): array
    {
        $all = get_option('moduly_settings', []);
        $stored = is_array($all) && isset($all[$moduleId]) && is_array($all[$moduleId]) ? $all[$moduleId] : [];
        $props = $this->schemas[$moduleId]['properties'] ?? [];
        $out = [];
        if (!is_array($props)) {
            return $out;
        }
        foreach ($props as $key => $schema) {
            if (!is_string($key) || !is_array($schema)) {
                continue;
            }
            $value = $stored[$key] ?? ($schema['default'] ?? null);
            $out[$key] = self::coerce($value, $schema) ?? ($schema['default'] ?? null);
        }
        return $out;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (in_array($level, ['error', 'critical', 'alert', 'emergency', 'warning'], true)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 12-factor: logs go to the PHP error log (stdout in containers).
            error_log((string) wp_json_encode(['moduly' => $level, 'message' => $message] + $context));
        }
    }

    /**
     * Invalid stored setting → null (caller falls back to the default); the stored value stays untouched.
     *
     * @param array<mixed> $schema
     */
    private static function coerce(mixed $value, array $schema): mixed
    {
        $type = $schema['type'] ?? 'string';
        $ok = match ($type) {
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_numeric($value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            default => false,
        };
        if (!$ok) {
            return null;
        }
        $value = match ($type) {
            'integer' => (int) $value,
            'number' => (float) $value,
            default => $value,
        };
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum']) {
                return null;
            }
            if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > $schema['maximum']) {
                return null;
            }
        }
        return $value;
    }
}
