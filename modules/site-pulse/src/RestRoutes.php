<?php

/**
 * REST proxy `sitepulse/v1` for the editor (cookie auth + wp_rest nonce via apiFetch):
 *   POST /analyze            {post_id, html, title, description, keywords} → POST /v1/analyze  (edit_post)
 *   GET  /insights/<post_id> history + link suggestions of the imported article                (edit_post)
 *   GET  /status             site id of the secret key ("Test connection")                       (manage_options)
 * The browser never sees the secret; upstream errors are mapped to WP_Error codes `sitepulse_*`.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse;

use PhpFasty\Module\SitePulse\Domain\AnalyzeRequest;
use PhpFasty\Module\SitePulse\Domain\SitePulseException;
use PhpFasty\Moduly\Wp\Support\Value;

final class RestRoutes
{
    public const NS = 'sitepulse/v1';

    public static function register(): void
    {
        register_rest_route(self::NS, '/analyze', [
            'methods' => 'POST',
            'callback' => [self::class, 'analyze'],
            'permission_callback' => [self::class, 'canEditPost'],
            'args' => [
                'post_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'html' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string', 'default' => ''],
                'description' => ['type' => 'string', 'default' => ''],
                'keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
            ],
        ]);
        register_rest_route(self::NS, '/insights/(?P<post_id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'insights'],
            'permission_callback' => [self::class, 'canEditPost'],
            'args' => ['post_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);
        register_rest_route(self::NS, '/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'status'],
            'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        ]);
    }

    public static function canEditPost(\WP_REST_Request $request): bool
    {
        $id = Value::int($request->get_param('post_id'));

        return $id > 0 && null !== get_post($id) && current_user_can('edit_post', $id);
    }

    public static function analyze(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return self::guard(static function () use ($request): array {
            $analyze = AnalyzeRequest::create(
                Value::str($request->get_param('html')),
                Value::str($request->get_param('title')),
                Value::str($request->get_param('description')),
                $request->get_param('keywords'),
            );

            return Options::service()->analyze($analyze)->toArray();
        });
    }

    public static function insights(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return self::guard(static function () use ($request): array {
            $post = get_post(Value::int($request->get_param('post_id')));
            if (!$post instanceof \WP_Post) {
                return ['found' => false];
            }

            return Options::service()->insights($post->ID, $post->post_name);
        });
    }

    public static function status(): \WP_REST_Response|\WP_Error
    {
        return self::guard(static fn (): array => ['ok' => true, 'site_id' => Options::service()->siteId()]);
    }

    public static function error(SitePulseException $e): \WP_Error
    {
        $data = ['status' => $e->httpStatus()];
        if ($e->retryAfter() > 0) {
            $data['retry_after'] = $e->retryAfter();
        }

        return new \WP_Error($e->errorCode(), $e->getMessage(), $data);
    }

    /** @param callable(): array<string, mixed> $call */
    private static function guard(callable $call): \WP_REST_Response|\WP_Error
    {
        try {
            $response = new \WP_REST_Response($call(), 200);
        } catch (SitePulseException $e) {
            return self::error($e);
        }
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
