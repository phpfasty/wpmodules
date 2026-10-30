<?php

/**
 * Class loader for the bundled ModulY core (lib/core/src, synced from phpfasty/moduly by tools/sync-core.sh)
 * and the WordPress adapter (src/).
 *
 * @package ModulY
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $map = [
        'PhpFasty\\Moduly\\Wp\\' => __DIR__ . '/src/',
        'PhpFasty\\Moduly\\' => __DIR__ . '/lib/core/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
