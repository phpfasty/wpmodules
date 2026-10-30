<?php

/**
 * Plugin Name:       ModulY
 * Plugin URI:        https://github.com/phpfasty/wpmodules
 * Description:       Secure module loader: install signed modules (cart, orders, …) from a zip, enable, disable and update them — validation, quarantine, signatures, audit log and safe mode are handled by the core.
 * Version:           0.1.2
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            PHP FastY
 * Author URI:        https://github.com/phpfasty
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       moduly
 *
 * @package ModulY
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MODULY_PLUGIN_FILE', __FILE__);
define('MODULY_PLUGIN_VERSION', '0.1.2');

require_once __DIR__ . '/autoload.php';

register_activation_hook(__FILE__, [PhpFasty\Moduly\Wp\Plugin::class, 'activate']);
PhpFasty\Moduly\Wp\Plugin::boot();
