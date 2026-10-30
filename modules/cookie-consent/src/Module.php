<?php

/**
 * Cookie consent module entry.
 *
 * @package ModulY\CookieConsent
 */

declare(strict_types=1);

namespace PhpFasty\Module\CookieConsent;

use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        $file = $context->path . '/assets/cookie-consent.js';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- own packaged asset.
        CookieConsent::configure($context->settings, is_file($file) ? (string) file_get_contents($file) : '');
        $host = $context->host;
        $host->on('filter', 'wpfasty_cookie_consent', [CookieConsent::class, 'state']);
        $host->on('action', 'wp_footer', [CookieConsent::class, 'printScript'], 50);
        $host->on('action', 'admin_init', [CookieConsent::class, 'privacyText']);
    }

    public function activate(ModuleContext $context): void
    {
    }

    public function deactivate(ModuleContext $context): void
    {
        // Disabled: the theme gets no `enabled` flag and hides the banner; visitors' cookies simply expire.
    }
}
