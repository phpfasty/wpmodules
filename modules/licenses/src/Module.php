<?php

/**
 * Licenses module entry.
 *
 * @package ModulY\Licenses
 */

declare(strict_types=1);

namespace PhpFasty\Module\Licenses;

use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        Licenses::configure($context->settings);
        $views = $context->path . '/views';
        $host = $context->host;
        $host->on('filter', 'wpfasty_view_roots', static fn (array $roots): array => array_merge($roots, [$views]));
        $host->on('filter', 'wpfasty_shop_product_fields', [Licenses::class, 'fields']);
        $host->on('filter', 'wpfasty_slots', [Licenses::class, 'slots'], 10, 2);
    }

    public function activate(ModuleContext $context): void
    {
    }

    public function deactivate(ModuleContext $context): void
    {
        // Disabled: the shop shows the default price again; the field values stay on the products.
    }
}
