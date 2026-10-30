<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Exception\ModulyException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST moduly/v1 (cookie auth + X-WP-Nonce, or application passwords):
 *   GET  /modules                      moduly_manage
 *   POST /modules        (file "package", enable=1)  moduly_install
 *   POST /modules/<id>/(enable|disable|rollback)    moduly_manage
 */
final class RestController
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        register_rest_route('moduly/v1', '/modules', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'list'],
                'permission_callback' => static fn (): bool => current_user_can('moduly_manage'),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'install'],
                'permission_callback' => static fn (): bool => current_user_can('moduly_install'),
            ],
        ]);
        register_rest_route('moduly/v1', '/modules/(?P<id>[a-z][a-z0-9-]{1,40})/(?P<op>enable|disable|rollback)', [
            'methods' => 'POST',
            'callback' => [$this, 'operate'],
            'permission_callback' => static fn (): bool => current_user_can('moduly_manage'),
        ]);
    }

    public function list(): WP_REST_Response
    {
        $out = [];
        $meta = OptionState::meta();
        foreach ($this->plugin->modules->records() as $rec) {
            $m = $this->plugin->modules->manifest($rec);
            $out[] = [
                'id' => $rec->id,
                'name' => $m->name ?? $rec->id,
                'version' => $rec->version,
                'previous' => $rec->previous,
                'status' => $rec->status,
                'error' => $rec->error,
                'signed' => $meta[$rec->id]['signed'] ?? false,
                'publisher' => $m->publisher ?? '',
                'permissions' => $m->permissions ?? [],
                'requires' => $m->requiresModules ?? [],
            ];
        }
        return new WP_REST_Response(['core' => Modules::coreVersion(), 'safe_mode' => $this->plugin->safe, 'modules' => $out]);
    }

    public function install(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $tmp = self::uploadedFile($request->get_file_params()['package'] ?? null);
        if ($tmp === '') {
            return new WP_Error('moduly_upload', __('Upload a module zip in the "package" field.', 'moduly'), ['status' => 400]);
        }
        try {
            $m = $this->plugin->modules->install($tmp, (string) $request->get_param('enable') === '1');
            return new WP_REST_Response(['id' => $m->id, 'version' => (string) $m->version], 201);
        } catch (ModulyException $e) {
            return new WP_Error('moduly_' . $e->reason, $e->getMessage(), ['status' => 422]);
        } finally {
            if (is_file($tmp)) {
                wp_delete_file($tmp);
            }
        }
    }

    public function operate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (string) $request->get_param('id');
        $op = (string) $request->get_param('op');
        if (!isset($this->plugin->modules->records()[$id])) {
            return new WP_Error('moduly_not_installed', __('Module is not installed.', 'moduly'), ['status' => 404]);
        }
        try {
            match ($op) {
                'enable' => $this->plugin->modules->enable($id),
                'disable' => $this->plugin->modules->disable($id),
                default => $this->plugin->modules->rollback($id),
            };
        } catch (Throwable $e) {
            return new WP_Error('moduly_' . ($e instanceof ModulyException ? $e->reason : 'failed'), $e->getMessage(), ['status' => 409]);
        }
        return new WP_REST_Response(['id' => $id, 'op' => $op, 'ok' => true]);
    }

    /** Temp path of a valid HTTP upload, '' otherwise. */
    private static function uploadedFile(mixed $file): string
    {
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return '';
        }
        return is_uploaded_file($file['tmp_name']) ? $file['tmp_name'] : '';
    }
}
