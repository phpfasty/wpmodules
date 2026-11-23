<?php

/**
 * SitePulse module entry: settings page, editor sidebar + REST proxy, tracker tag.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

use PhpFasty\Moduly\Contract\ModuleInterface;
use PhpFasty\Moduly\Kernel\ModuleContext;

final class Module implements ModuleInterface
{
    public function boot(ModuleContext $context): void
    {
        $timeout = is_int($context->settings['timeout_seconds'] ?? null) ? $context->settings['timeout_seconds'] : 15;
        Options::configure($timeout);
        Assets::configure($context->path.'/assets');
        $host = $context->host;
        $host->on('action', 'init', [Editor::class, 'registerMeta']);
        $host->on('action', 'enqueue_block_editor_assets', [Editor::class, 'enqueue']);
        $host->on('action', 'rest_api_init', [RestRoutes::class, 'register']);
        $host->on('action', 'wp_head', [Tracker::class, 'print'], 20);
        $host->on('action', 'admin_menu', [AdminPage::class, 'menu']);
        $host->on('action', 'admin_init', [AdminPage::class, 'registerSettings']);
        $host->on('action', 'admin_init', [Tracker::class, 'privacyText']);
    }

    public function activate(ModuleContext $context): void
    {
        Options::ensureSecretOption();
    }

    public function deactivate(ModuleContext $context): void
    {
        // Disabled: no tag, no sidebar, the REST namespace answers 503; settings and post meta stay.
    }
}
