<?php

/**
 * Renamed hooks: the new name is canonical, the old one keeps working as a deprecated alias.
 *
 * @package ModulY
 */

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp\Support;

final class Hooks
{
    /** Version of ModulY in which the latte_shop_* names were deprecated (removal not before 1.0). */
    public const DEPRECATED_SINCE = '0.2.0';

    /**
     * Filters `$value` through the deprecated name (only when something is hooked to it, with a
     * deprecation notice) and then through the new name.
     */
    /**
     * @param non-empty-string $name
     * @param non-empty-string $deprecated
     */
    public static function filter(string $name, string $deprecated, mixed $value, mixed ...$args): mixed
    {
        if (!function_exists('apply_filters')) {
            return $value;
        }
        if (function_exists('has_filter') && false !== has_filter($deprecated) && function_exists('apply_filters_deprecated')) {
            $value = apply_filters_deprecated($deprecated, [$value, ...$args], self::DEPRECATED_SINCE, $name);
        }

        return apply_filters($name, $value, ...$args);
    }

    /**
     * Fires the new action, then the deprecated alias (only when hooked, with a deprecation notice).
     *
     * @param non-empty-string $name
     * @param non-empty-string $deprecated
     */
    public static function action(string $name, string $deprecated, mixed ...$args): void
    {
        do_action($name, ...$args);
        if (false !== has_action($deprecated)) {
            do_action_deprecated($deprecated, $args, self::DEPRECATED_SINCE, $name);
        }
    }
}
