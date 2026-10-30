<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Kernel\AuditLogInterface;

/**
 * Audit trail in `{prefix}moduly_audit` + one JSON line in the PHP error log per event.
 */
final class AuditLog implements AuditLogInterface
{
    public const DB_VERSION = '1';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'moduly_audit';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ts datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ip_hash char(64) NOT NULL DEFAULT '',
            action varchar(32) NOT NULL,
            module_id varchar(64) NOT NULL DEFAULT '',
            version varchar(64) NOT NULL DEFAULT '',
            result varchar(32) NOT NULL,
            context longtext NULL,
            PRIMARY KEY  (id),
            KEY module_ts (module_id, ts)
        ) {$charset};");
        update_option('moduly_audit_db', self::DB_VERSION, false);
    }

    public function record(string $action, string $moduleId, string $version, string $result, array $context = []): void
    {
        global $wpdb;
        $row = [
            'ts' => gmdate('Y-m-d H:i:s'),
            'user_id' => get_current_user_id(),
            'ip_hash' => self::ipHash(),
            'action' => substr($action, 0, 32),
            'module_id' => substr($moduleId, 0, 64),
            'version' => substr($version, 0, 64),
            'result' => substr($result, 0, 32),
            'context' => (string) wp_json_encode($context),
        ];
        if (get_option('moduly_audit_db') === self::DB_VERSION) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- append-only audit table owned by this plugin.
            $wpdb->insert(self::table(), $row);
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- audit line for log shippers.
        error_log((string) wp_json_encode(['moduly_audit' => $row['action'], 'module' => $moduleId, 'version' => $version, 'result' => $result, 'user' => $row['user_id']]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit = 20): array
    {
        global $wpdb;
        if (get_option('moduly_audit_db') !== self::DB_VERSION) {
            return [];
        }
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, admin screen only.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ts, user_id, action, module_id, version, result FROM %i ORDER BY id DESC LIMIT %d", $table, $limit), ARRAY_A);
        return is_array($rows) ? array_values($rows) : [];
    }

    /** Daily retention (MODULY_AUDIT_DAYS, default 180). Audit rows are operational logs, not user content. */
    public static function prune(): void
    {
        global $wpdb;
        if (get_option('moduly_audit_db') !== self::DB_VERSION) {
            return;
        }
        $days = defined('MODULY_AUDIT_DAYS') ? max(1, (int) constant('MODULY_AUDIT_DAYS')) : 180;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE ts < %s", $table, gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
    }

    public static function ipHash(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- only hashed, never stored raw.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        return $ip === '' ? '' : hash('sha256', $ip . wp_salt('auth'));
    }
}
