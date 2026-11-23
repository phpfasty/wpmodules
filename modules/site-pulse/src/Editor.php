<?php

/**
 * Block editor: post meta `_sitepulse_keywords` (≤ 5, REST-visible to editors of the post) and the sidebar
 * script, shipped inline (modules may live outside the web root) on top of the core wp-* packages.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

use PhpFasty\Module\SitePulse\Domain\Keywords;

final class Editor
{
    public const META = '_sitepulse_keywords';
    public const HANDLE = 'sitepulse-editor';

    public static function registerMeta(): void
    {
        register_post_meta('', self::META, [
            'type' => 'array',
            'single' => true,
            'default' => [],
            'description' => 'SitePulse focus keywords (first = primary).',
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'maxItems' => Keywords::MAX,
                    'items' => ['type' => 'string', 'maxLength' => Keywords::MAX_LENGTH],
                ],
            ],
            'sanitize_callback' => static fn (mixed $value): array => Keywords::normalize($value),
            'auth_callback' => static fn (bool $allowed, string $key, int $postId): bool => current_user_can('edit_post', $postId),
        ]);
    }

    public static function enqueue(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (null === $screen || 'post' !== $screen->base || !current_user_can('edit_posts')) {
            return;
        }
        $script = Assets::read('editor.js');
        if ('' === $script) {
            return;
        }
        $deps = ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-blocks', 'wp-core-data'];
        wp_register_script(self::HANDLE, false, $deps, '0.1.0', true);
        $settings = Options::settings();
        $config = [
            'configured' => Options::configured(),
            'autoAnalyze' => $settings->autoAnalyze,
            'metaKey' => self::META,
            'maxKeywords' => Keywords::MAX,
            'settingsUrl' => current_user_can('manage_options') ? admin_url('options-general.php?page=sitepulse') : '',
        ];
        wp_add_inline_script(self::HANDLE, 'window.sitePulseEditor='.wp_json_encode($config).";\n".$script);
        wp_enqueue_script(self::HANDLE);
        wp_register_style(self::HANDLE, false, [], '0.1.0');
        wp_add_inline_style(self::HANDLE, Assets::read('editor.css'));
        wp_enqueue_style(self::HANDLE);
    }
}
