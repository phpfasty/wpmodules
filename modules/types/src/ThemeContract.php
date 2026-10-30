<?php

/**
 * Types module: the one theme interface the Registry implements, declared only when the theme has not loaded it.
 * Identical to wp-fasty `themes/latte/classes/Core/BootableServiceInterface.php` (keep in sync); once declared
 * here the theme's autoloader simply never loads its copy.
 *
 * @package ModulY\Types
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types {

    /** Loading this class declares WPFasty\Core\BootableServiceInterface when missing. */
    final class ThemeContract
    {
        public const INTERFACE = 'WPFasty\\Core\\BootableServiceInterface';
    }
}

namespace WPFasty\Core {

    if (!interface_exists(BootableServiceInterface::class, false)) {
        interface BootableServiceInterface
        {
            public function boot(): void;
        }
    }
}
