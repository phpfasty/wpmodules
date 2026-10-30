<?php

declare(strict_types=1);

namespace PhpFasty\Module\Types\Migrations;

use PhpFasty\Module\Types\Model\Registry;
use PhpFasty\Moduly\Migration\MigrationInterface;

/**
 * A field whose kind or key changed declares its old keys in `aliases`. This copies the first valid old value to
 * the new key where the new key is still absent — additive only: old keys are kept (read fallback, rollback to the
 * theme Registry, prune is a separate explicit command). Invalid old values are logged and skipped (reads give the
 * default). Batched by post ID with a cursor option, so a re-run continues and a finished run is a no-op.
 */
final class Migration_0002_AliasBackfill implements MigrationInterface
{
    public const CURSOR = 'moduly_types_m2_cursor';

    public function __construct(private readonly Registry $types)
    {
    }

    public function version(): int
    {
        return 2;
    }

    public function up(int $batchSize): bool
    {
        global $wpdb;
        $cursor = (array) get_option(self::CURSOR, []);
        $budget = max(1, $batchSize);
        foreach ($this->types->all() as $type) {
            $fields = array_filter($type->fields, static fn ($f): bool => !empty($f->def['aliases']));
            if ($fields === []) {
                continue;
            }
            $last = (int) ($cursor[$type->name] ?? 0);
            if ($last < 0) {
                continue; // this post type is done
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- batched ID scan; meta is read via the meta API below
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                $type->name,
                $last,
                $budget
            ));
            foreach ($ids as $id) {
                $id = (int) $id;
                foreach ($fields as $field) {
                    $this->backfill($id, $field);
                }
                $last = $id;
            }
            $cursor[$type->name] = count($ids) < $budget ? -1 : $last;
            update_option(self::CURSOR, $cursor, false);
            if (count($ids) >= $budget) {
                return false; // more of this type: next batch
            }
            $budget -= count($ids);
            if ($budget <= 0) {
                return false;
            }
        }
        return true;
    }

    private function backfill(int $postId, \PhpFasty\Module\Types\Model\Field $field): void
    {
        if (metadata_exists('post', $postId, $field->metaKey)) {
            return;
        }
        foreach ((array) $field->def['aliases'] as $old) {
            $old = (string) $old;
            if (!metadata_exists('post', $postId, $old)) {
                continue;
            }
            $raw = (string) get_post_meta($postId, $old, true);
            $value = $field->toStorage($raw);
            if ($raw === '' || $value === null) {
                do_action('moduly_types_invalid_value', $postId, $field->name, $old, 'alias value not convertible, skipped');
                return;
            }
            add_post_meta($postId, $field->metaKey, $value, true);
            return;
        }
    }
}
