<?php

/**
 * Cart module entry.
 *
 * @package ModulY\Cart
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        Cart::configure($context->settings);
        $context->container->singleton('cart.api', static fn (): CartApi => new CartApi());
        if ($context->container->has('wp.rate_limiter')) {
            $limiter = $context->container->get('wp.rate_limiter');
            if (is_object($limiter) && method_exists($limiter, 'hit')) {
                Handlers::setLimiter(static fn (string $bucket): bool => (bool) $limiter->hit($bucket, 30));
            }
        }
        $host = $context->host;
        $host->on('action', 'wpfasty_types_loaded', [Catalog::class, 'setRegistry'], 5);
        foreach (['admin_post_latte_shop_cart', 'admin_post_nopriv_latte_shop_cart'] as $hook) {
            $host->on('action', $hook, [Handlers::class, 'cart']);
        }
        $host->on('filter', 'wpfasty_shop_state', [Shop::class, 'state']);
        $host->on('filter', 'wpfasty_shop_cart', [Shop::class, 'cart']);
        $host->on('filter', 'wpfasty_shop_product', [Catalog::class, 'product'], 10, 2);
    }

    public function activate(ModuleContext $context): void
    {
        Pages::ensure();
    }

    public function deactivate(ModuleContext $context): void
    {
        // Carts live in visitors' cookies; nothing to stop or delete.
    }
}
