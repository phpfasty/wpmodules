<?php

/**
 * Orders module entry.
 *
 * @package ModulY\Orders
 */

declare(strict_types=1);

namespace PhpFasty\Module\Orders;

use PhpFasty\Module\Cart\CartApi;
use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        $cart = $context->container->get('cart.api');
        if (!$cart instanceof CartApi) {
            throw new \RuntimeException('cart.api service is missing');
        }
        Orders::setCart($cart);
        Checkout::setCart($cart);
        $host = $context->host;
        $host->on('action', 'init', [Orders::class, 'registerType'], 11);
        $host->on('action', 'admin_init', [Orders::class, 'registerAdmin']);
        foreach (['admin_post_latte_shop_checkout', 'admin_post_nopriv_latte_shop_checkout'] as $hook) {
            $host->on('action', $hook, [Checkout::class, 'handle']);
        }
        $host->on('filter', 'wpfasty_shop_state', [Checkout::class, 'state'], 11);
        $host->on('filter', 'wpfasty_shop_checkout', [Checkout::class, 'checkout']);
        $host->on('filter', 'wpfasty_shop_order', [Checkout::class, 'order']);
    }

    public function activate(ModuleContext $context): void
    {
        Orders::registerType();
    }

    public function deactivate(ModuleContext $context): void
    {
        // Orders stay in the database (private posts); nothing is deleted.
    }
}
