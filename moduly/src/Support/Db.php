<?php

/**
 * Typed access to the WordPress database object.
 *
 * @package ModulY
 */

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp\Support;

final class Db
{
    public static function wpdb(): \wpdb
    {
        /** @var \wpdb $wpdb (a test double in unit tests) */
        global $wpdb;

        return $wpdb;
    }
}
