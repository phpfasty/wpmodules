<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Exception\ModulyException;
use PhpFasty\Moduly\Kernel\ModuleRecord;
use Throwable;

/**
 * Plugins → Modules: upload a module zip, enable / disable / roll back / remove, recent audit events.
 * Every action: capability + nonce + POST, then a safe redirect with a result code.
 */
final class AdminPage
{
    public const SLUG = 'moduly';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action(is_multisite() ? 'network_admin_menu' : 'admin_menu', [$this, 'menu']);
        add_action('admin_post_moduly_upload', [$this, 'upload']);
        add_action('admin_post_moduly_action', [$this, 'action']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('network_admin_notices', [$this, 'notices']);
        add_filter('plugin_action_links_' . plugin_basename(MODULY_PLUGIN_FILE), [$this, 'links']);
    }

    public function menu(): void
    {
        add_plugins_page(__('Modules', 'moduly'), __('Modules (ModulY)', 'moduly'), 'moduly_manage', self::SLUG, [$this, 'render']);
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function links(array $links): array
    {
        $links['moduly'] = sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Modules', 'moduly'));
        return $links;
    }

    /** @param array<string, string> $args */
    public static function url(array $args = []): string
    {
        $base = is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php');
        return add_query_arg(['page' => self::SLUG] + $args, $base);
    }

    public function notices(): void
    {
        if (!current_user_can('moduly_manage')) {
            return;
        }
        if ($this->plugin->safe) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html__('ModulY safe mode is on: no modules are loaded on this request.', 'moduly'));
        }
        foreach ($this->plugin->modules->records() as $rec) {
            if ($rec->status === ModuleRecord::FAILED) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                    esc_html(sprintf(
                        /* translators: 1: module id, 2: error */
                        __('ModulY disabled the module "%1$s" after an error: %2$s', 'moduly'),
                        $rec->id,
                        $rec->error
                    )),
                    esc_url(self::url()),
                    esc_html__('Manage modules', 'moduly')
                );
            }
        }
    }

    public function upload(): void
    {
        $this->guard('moduly_install', 'moduly_upload');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce verified in guard(); uploaded file array; only tmp_name/size/error are read and validated.
        $file = isset($_FILES['package']) && is_array($_FILES['package']) ? $_FILES['package'] : [];
        $tmp = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            $this->back(['moduly_error' => 'upload']);
        }
        $enable = isset($_POST['enable']) && sanitize_key(wp_unslash((string) $_POST['enable'])) === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        try {
            $m = $this->plugin->modules->install($tmp, $enable);
            $this->back(['moduly_done' => $enable ? 'installed_enabled' : 'installed', 'module' => $m->id]);
        } catch (ModulyException $e) {
            set_transient('moduly_msg_' . get_current_user_id(), $e->getMessage(), 120);
            $this->back(['moduly_error' => $e->reason]);
        } finally {
            if (is_file($tmp)) {
                wp_delete_file($tmp);
            }
        }
    }

    public function action(): void
    {
        $this->guard('moduly_manage', 'moduly_action');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
        $id = isset($_POST['module']) ? sanitize_key(wp_unslash((string) $_POST['module'])) : '';
        // phpcs:enable
        if (preg_match('/^[a-z][a-z0-9-]{1,40}$/', $id) !== 1 || !isset($this->plugin->modules->records()[$id])) {
            $this->back(['moduly_error' => 'unknown_module']);
        }
        try {
            match ($op) {
                'enable' => $this->plugin->modules->enable($id),
                'disable' => $this->plugin->modules->disable($id),
                'rollback' => $this->plugin->modules->rollback($id),
                'remove' => $this->plugin->modules->remove($id),
                default => throw new ModulyException('bad_op', __('Unknown action.', 'moduly')),
            };
            $this->back(['moduly_done' => $op, 'module' => $id]);
        } catch (Throwable $e) {
            set_transient('moduly_msg_' . get_current_user_id(), $e->getMessage(), 120);
            $this->back(['moduly_error' => $e instanceof ModulyException ? $e->reason : 'failed', 'module' => $id]);
        }
    }

    public function render(): void
    {
        if (!current_user_can('moduly_manage')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage modules.', 'moduly'), '', ['response' => 403]);
        }
        $modules = $this->plugin->modules;
        $meta = OptionState::meta();
        $canInstall = current_user_can('moduly_install');
        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('Modules', 'moduly') . '</h1>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: core version */
            __('ModulY core %s. Modules are verified (schema, sha256, publisher signature, static scan) before anything is executed.', 'moduly'),
            Modules::coreVersion()
        )) . '</p>';
        $this->result();

        if ($canInstall) {
            echo '<h2>' . esc_html__('Upload a module', 'moduly') . '</h2>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('moduly_upload');
            echo '<input type="hidden" name="action" value="moduly_upload">';
            echo '<p><input type="file" name="package" accept=".zip,application/zip" required> ';
            echo '<label><input type="checkbox" name="enable" value="1" checked> ' . esc_html__('Enable after install', 'moduly') . '</label></p>';
            submit_button(__('Install module', 'moduly'), 'primary', 'submit', false);
            echo '</form>';
        } else {
            echo '<p>' . esc_html__('Module uploads are disabled here (DISALLOW_FILE_MODS or missing capability). Use WP-CLI: wp moduly install <zip>.', 'moduly') . '</p>';
        }

        echo '<h2>' . esc_html__('Installed modules', 'moduly') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Module', 'moduly'), __('Version', 'moduly'), __('Status', 'moduly'), __('Signature', 'moduly'), __('Permissions', 'moduly'), __('Requires', 'moduly'), __('Actions', 'moduly')] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $records = $modules->records();
        if ($records === []) {
            echo '<tr><td colspan="7">' . esc_html__('No modules installed yet.', 'moduly') . '</td></tr>';
        }
        foreach ($records as $rec) {
            $m = $modules->manifest($rec);
            $signed = $meta[$rec->id]['signed'] ?? false;
            echo '<tr><td><strong>' . esc_html($m->name ?? $rec->id) . '</strong><br><code>' . esc_html($rec->id) . '</code>';
            if ($m !== null && $m->description !== '') {
                echo '<p class="description">' . esc_html($m->description) . '</p>';
            }
            echo '</td><td>' . esc_html($rec->version) . ($rec->previous !== '' ? '<br><small>' . esc_html(sprintf(/* translators: %s: version */ __('previous: %s', 'moduly'), $rec->previous)) . '</small>' : '') . '</td>';
            echo '<td>' . esc_html(self::statusLabel($rec->status)) . ($rec->error !== '' ? '<br><small>' . esc_html($rec->error) . '</small>' : '') . '</td>';
            echo '<td>' . esc_html($signed ? sprintf(/* translators: %s: publisher */ __('signed by %s', 'moduly'), $m->publisher ?? '?') : __('unsigned (development)', 'moduly')) . '</td>';
            echo '<td><code>' . esc_html(implode(', ', $m->permissions ?? [])) . '</code></td>';
            echo '<td>' . esc_html(implode(', ', array_map(static fn (string $k, string $v): string => "{$k} {$v}", array_keys($m->requiresModules ?? []), $m->requiresModules ?? []))) . '</td><td>';
            $ops = $rec->status === ModuleRecord::ENABLED ? ['disable' => __('Disable', 'moduly')] : ['enable' => __('Enable', 'moduly'), 'remove' => __('Remove files', 'moduly')];
            if ($rec->previous !== '') {
                $ops['rollback'] = __('Roll back', 'moduly');
            }
            foreach ($ops as $op => $label) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0">';
                wp_nonce_field('moduly_action');
                echo '<input type="hidden" name="action" value="moduly_action"><input type="hidden" name="op" value="' . esc_attr($op) . '"><input type="hidden" name="module" value="' . esc_attr($rec->id) . '">';
                submit_button($label, $op === 'enable' ? 'primary small' : 'secondary small', 'submit', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Removing a module deletes its code only; posts, orders and settings it created stay in the database.', 'moduly') . '</p>';

        $events = AuditLog::latest(15);
        if ($events !== []) {
            echo '<h2>' . esc_html__('Recent activity', 'moduly') . '</h2><table class="widefat striped"><tbody>';
            foreach ($events as $e) {
                echo '<tr><td>' . esc_html((string) ($e['ts'] ?? '')) . ' UTC</td><td>' . esc_html((string) ($e['action'] ?? '')) . '</td><td>' . esc_html((string) ($e['module_id'] ?? '')) . ' ' . esc_html((string) ($e['version'] ?? '')) . '</td><td>' . esc_html((string) ($e['result'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private function result(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of a redirect result code.
        $done = isset($_GET['moduly_done']) ? sanitize_key(wp_unslash((string) $_GET['moduly_done'])) : '';
        $error = isset($_GET['moduly_error']) ? sanitize_key(wp_unslash((string) $_GET['moduly_error'])) : '';
        $module = isset($_GET['module']) ? sanitize_key(wp_unslash((string) $_GET['module'])) : '';
        // phpcs:enable
        if ($done !== '') {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(sprintf(/* translators: 1: action, 2: module */ __('Done: %1$s %2$s.', 'moduly'), str_replace('_', ' ', $done), $module)));
        }
        if ($error !== '') {
            $msg = get_transient('moduly_msg_' . get_current_user_id());
            delete_transient('moduly_msg_' . get_current_user_id());
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s <code>%s</code></p></div>',
                esc_html(is_string($msg) && $msg !== '' ? $msg : __('The module was not installed or changed.', 'moduly')),
                esc_html($error)
            );
        }
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            ModuleRecord::ENABLED => __('Enabled', 'moduly'),
            ModuleRecord::FAILED => __('Failed (disabled)', 'moduly'),
            default => __('Installed', 'moduly'),
        };
    }

    private function guard(string $cap, string $nonce): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'post' || !current_user_can($cap)) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'moduly'), '', ['response' => 403]);
        }
        check_admin_referer($nonce); // dies on a missing or invalid nonce
    }

    /** @param array<string, string> $args */
    private function back(array $args): never
    {
        wp_safe_redirect(self::url($args));
        exit;
    }
}
