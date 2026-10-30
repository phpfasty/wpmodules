<?php

/**
 * Downloads module entry.
 *
 * @package ModulY\Downloads
 */

declare(strict_types=1);

namespace PhpFasty\Module\Downloads;

use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        Downloads::configure($context->settings);
        $host = $context->host;
        $host->on('action', 'wpfasty_types_loaded', [Downloads::class, 'setRegistry'], 6);
        foreach (['admin_post_moduly_download', 'admin_post_nopriv_moduly_download'] as $hook) {
            $host->on('action', $hook, [Downloads::class, 'serve']);
        }
        $host->on('filter', 'wpfasty_shop_order', [Downloads::class, 'order'], 20);
        $host->on('action', 'wpfasty_shop_order_status', [Downloads::class, 'onStatus'], 10, 3);
        $host->on('action', 'save_post_product', [Downloads::class, 'onSave'], 20);
        foreach (['added_post_meta', 'updated_post_meta'] as $hook) {
            $host->on('action', $hook, [Downloads::class, 'onMeta'], 20, 3);
        }
    }

    public function activate(ModuleContext $context): void
    {
        Storage::ensure();
    }

    public function deactivate(ModuleContext $context): void
    {
        // Files stay private in uploads/moduly-private; links answer 503 while disabled (endpoint declared).
    }
}
