<?php

/**
 * Types module: Prune.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types;

use PhpFasty\Module\Types\Model\Registry;
use PhpFasty\Moduly\Wp\Support\Db;
use PhpFasty\Moduly\Wp\Support\Value;

/**
 * Finds post meta of declared post types that no field (meta key or alias) claims any more, under the key prefixes
 * the fields use (e.g. `_product_`). Deleting is explicit only: a JSON backup is written first, then rows are
 * removed in batches under a lock. Never runs from a request or a migration.
 *
 * @moduly-destructive-cli the only module file allowed to delete rows (reached from `wp moduly types prune --yes`)
 */
final class Prune
{
    public function __construct(private readonly Registry $types)
    {
    }

    /** @return list<array{post_id: int, post_type: string, key: string, value: string}> */
    public function candidates(int $limit = 0): array
    {
        $wpdb = Db::wpdb();
        $rows = [];
        foreach ($this->types->all() as $type) {
            $known = [];
            $prefixes = [];
            foreach ($type->fields as $name => $field) {
                $known[$field->metaKey] = true;
                foreach ((array) ($field->def['aliases'] ?? []) as $alias) {
                    $known[Value::str($alias)] = true;
                }
                $suffix = '_'.$name;
                if (str_ends_with($field->metaKey, $suffix) && strlen($field->metaKey) > strlen($suffix) + 1) {
                    $prefixes[substr($field->metaKey, 0, -strlen($name))] = true;
                }
            }
            foreach (array_keys($prefixes) as $prefix) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only listing for a CLI command
                $found = $wpdb->get_results($wpdb->prepare(
                    'SELECT pm.post_id, pm.meta_key, pm.meta_value FROM %i pm
                     JOIN %i p ON p.ID = pm.post_id
                     WHERE p.post_type = %s AND pm.meta_key LIKE %s ORDER BY pm.meta_id ASC',
                    $wpdb->postmeta,
                    $wpdb->posts,
                    $type->name,
                    $wpdb->esc_like((string) $prefix).'%'
                ), ARRAY_A);
                foreach ((array) $found as $r) {
                    if (isset($known[Value::str($r['meta_key'])])) {
                        continue;
                    }
                    $rows[] = [
                        'post_id' => Value::int($r['post_id']),
                        'post_type' => $type->name,
                        'key' => Value::str($r['meta_key']),
                        'value' => Value::str($r['meta_value']),
                    ];
                    if ($limit > 0 && count($rows) >= $limit) {
                        return $rows;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<array{post_id: int, post_type: string, key: string, value: string}> $rows
     *
     * @return string backup file path
     */
    public static function backup(array $rows, string $dir): string
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException(sprintf('Cannot create backup directory %s', $dir));
        }
        if (!file_exists($dir.'/index.php')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- WP-CLI only, private backup dir
            file_put_contents($dir.'/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($dir.'/.htaccess')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- WP-CLI only, private backup dir
            file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
        }
        $file = $dir.'/types-prune-'.gmdate('Ymd-His').'-'.wp_generate_password(6, false).'.json';
        $json = wp_json_encode(['created' => gmdate('c'), 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // phpcs:ignore WordPress.WP.AlternativeFunctions -- WP-CLI only, private backup dir
        if (!is_string($json) || false === file_put_contents($file, $json)) {
            throw new \RuntimeException(sprintf('Cannot write backup %s', $file));
        }

        return $file;
    }

    /**
     * Deletes exactly the backed-up rows (key + value match), in batches.
     *
     * @param list<array{post_id: int, post_type: string, key: string, value: string}> $rows
     */
    public static function delete(array $rows, int $batch, ?callable $progress = null): int
    {
        $deleted = 0;
        foreach (array_chunk($rows, max(1, $batch)) as $chunk) {
            foreach ($chunk as $r) {
                if (delete_post_meta($r['post_id'], $r['key'], $r['value'])) {
                    ++$deleted;
                }
            }
            if (null !== $progress) {
                $progress($deleted);
            }
        }

        return $deleted;
    }
}
