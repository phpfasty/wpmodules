<?php

/**
 * Verified reviews module entry.
 *
 * @package ModulY\Reviews
 */

declare(strict_types=1);

namespace PhpFasty\Module\Reviews;

use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        Reviews::configure($context->settings);
        $host = $context->host;
        $host->on('action', 'wpfasty_types_loaded', [Reviews::class, 'setRegistry'], 7);
        // After the latte parent's comments-off default (priority 20): products stay open while the module runs.
        $host->on('filter', 'comments_open', [Reviews::class, 'open'], 30, 2);
        $host->on('filter', 'preprocess_comment', [Reviews::class, 'preprocess'], 5);
        $host->on('action', 'comment_post', [Reviews::class, 'saved']);
        $host->on('action', 'wp_set_comment_status', [Reviews::class, 'statusChanged']);
        $host->on('filter', 'wpfasty_shop_product', [Reviews::class, 'product'], 20, 2);
    }

    public function activate(ModuleContext $context): void
    {
    }

    public function deactivate(ModuleContext $context): void
    {
        // Reviews stay as comments; product rating fields keep the last aggregate.
    }
}
