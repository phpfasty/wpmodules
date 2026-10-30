<?php

/**
 * Types module: Cli.
 *
 * @package ModulY\Types
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types;

use PhpFasty\Moduly\Wp\OptionLock;

/**
 * `wp moduly types` — WP-CLI commands of the types module.
 */
final class Cli
{
    /**
     * Lists (and, with --yes only, deletes) post meta no declared field or alias claims.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List candidates and write nothing (default when --yes is absent).
     *
     * [--yes]
     * : Write a JSON backup to wp-content/moduly/backups, then delete the listed rows.
     *
     * [--batch=<n>]
     * : Rows per delete batch. Default 200.
     *
     * [--format=<format>]
     * : table|json. Default table.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        $registry = Data::registry();
        if ($registry === null) {
            \WP_CLI::error('No types registry: the active theme declares no types (or the module is not active).');
        }
        $rows = (new Prune($registry))->candidates();
        $format = (string) ($assoc['format'] ?? 'table');
        if ($rows === []) {
            \WP_CLI::success('Nothing to prune.');
            return;
        }
        \WP_CLI\Utils\format_items($format, $rows, ['post_id', 'post_type', 'key', 'value']);
        if (empty($assoc['yes']) || !empty($assoc['dry-run'])) {
            \WP_CLI::log(sprintf('Dry run: %d row(s) would be deleted. Re-run with --yes to back up and delete.', count($rows)));
            return;
        }
        $lock = new OptionLock();
        if (!$lock->acquire('moduly_types_prune', 600)) {
            \WP_CLI::error('Another prune is running.');
        }
        try {
            $file = Prune::backup($rows, WP_CONTENT_DIR . '/moduly/backups');
            \WP_CLI::log('Backup: ' . $file);
            $n = Prune::delete($rows, (int) ($assoc['batch'] ?? 200), static function (int $done): void {
                \WP_CLI::log("deleted {$done}");
            });
            \WP_CLI::success("Deleted {$n} row(s).");
        } finally {
            $lock->release('moduly_types_prune');
        }
    }
}
