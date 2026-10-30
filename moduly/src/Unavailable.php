<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Manifest\Manifest;
use WP_Error;

/**
 * Disable scenario: entry points declared in module.json `endpoints` of installed modules that are not running
 * (disabled, failed, safe mode) are still answered — HTTP 503 + Retry-After, no-cache, an escaped i18n page with a
 * safe "Back to site" link (admin-post forms) or a 503 WP_Error (REST). Never a blank page.
 */
final class Unavailable
{
    public const RETRY_AFTER = 300;

    /**
     * Endpoints of installed modules that did not boot in this request.
     *
     * @param array<string, Manifest> $installed id → manifest of every installed module
     * @param list<string> $booted ids running in this request
     * @return array{actions: list<string>, rest: list<string>}
     */
    public static function collect(array $installed, array $booted): array
    {
        $out = ['actions' => [], 'rest' => []];
        $live = ['actions' => [], 'rest' => []];
        foreach ($installed as $id => $m) {
            $bucket = in_array($id, $booted, true) ? 'live' : 'out';
            foreach (['actions', 'rest'] as $kind) {
                foreach ($m->endpoints[$kind] as $name) {
                    if ($bucket === 'live') {
                        $live[$kind][] = $name;
                    } else {
                        $out[$kind][] = $name;
                    }
                }
            }
        }
        // An endpoint also served by a running module is not unavailable.
        return [
            'actions' => array_values(array_unique(array_diff($out['actions'], $live['actions']))),
            'rest' => array_values(array_unique(array_diff($out['rest'], $live['rest']))),
        ];
    }

    /** @param list<string> $prefixes */
    public static function restMatches(string $route, array $prefixes): bool
    {
        $route = '/' . ltrim($route, '/');
        foreach ($prefixes as $p) {
            $p = '/' . trim($p, '/');
            if ($route === $p || str_starts_with($route, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @param array{actions: list<string>, rest: list<string>} $endpoints */
    public static function register(array $endpoints): void
    {
        foreach ($endpoints['actions'] as $action) {
            add_action('admin_post_' . $action, [self::class, 'page'], 0);
            add_action('admin_post_nopriv_' . $action, [self::class, 'page'], 0);
        }
        if ($endpoints['rest'] !== []) {
            $prefixes = $endpoints['rest'];
            add_filter('rest_pre_dispatch', static function (mixed $result, mixed $server, \WP_REST_Request $request) use ($prefixes): mixed {
                if ($result !== null || !self::restMatches($request->get_route(), $prefixes)) {
                    return $result;
                }
                return new WP_Error('moduly_unavailable', __('This feature is temporarily unavailable. Please try again later.', 'moduly'), ['status' => 503]);
            }, 0, 3);
            add_filter('rest_post_dispatch', static function (mixed $response, mixed $server, \WP_REST_Request $request) use ($prefixes): mixed {
                if ($response instanceof \WP_REST_Response && $response->get_status() === 503 && self::restMatches($request->get_route(), $prefixes)) {
                    $response->header('Retry-After', (string) self::RETRY_AFTER);
                }
                return $response;
            }, 10, 3);
        }
    }

    /** Safe "Back to site" target: a same-site referer, else the home page. */
    public static function backUrl(): string
    {
        $home = home_url('/');
        $ref = wp_get_referer();
        return $ref !== false ? wp_validate_redirect($ref, $home) : $home;
    }

    public static function html(string $backUrl): string
    {
        return '<h1>' . esc_html__('Temporarily unavailable', 'moduly') . '</h1>'
            . '<p>' . esc_html__('This feature is temporarily unavailable. Please try again later.', 'moduly') . '</p>'
            . '<p><a href="' . esc_url($backUrl) . '">' . esc_html__('Back to site', 'moduly') . '</a></p>';
    }

    /** admin-post handler of a disabled module's action. */
    public static function page(): void
    {
        nocache_headers();
        if (!headers_sent()) {
            header('Retry-After: ' . self::RETRY_AFTER);
        }
        wp_die(
            self::html(self::backUrl()), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html__/esc_url above.
            esc_html__('Temporarily unavailable', 'moduly'),
            ['response' => 503, 'back_link' => false]
        );
    }
}
