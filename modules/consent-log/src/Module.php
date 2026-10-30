<?php

/**
 * Consent log module entry.
 *
 * @package ModulY\ConsentLog
 */

declare(strict_types=1);

namespace PhpFasty\Module\ConsentLog;

use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        Consents::configure($context->settings);
        $host = $context->host;
        $host->on('filter', 'wpfasty_shop_checkout_errors', [Consents::class, 'onValidate']);
        $host->on('action', 'latte_shop_order_created', [Consents::class, 'onOrderCreated']);
        $host->on('filter', 'wpfasty_shop_checkout', [Consents::class, 'checkout'], 20);
        $host->on('filter', 'wp_privacy_personal_data_exporters', [Privacy::class, 'exporters']);
        $host->on('filter', 'wp_privacy_personal_data_erasers', [Privacy::class, 'erasers']);
    }

    public function activate(ModuleContext $context): void
    {
    }

    public function deactivate(ModuleContext $context): void
    {
        // Consent records stay on the orders (legal proof); nothing is deleted.
    }
}
