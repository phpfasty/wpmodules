<?php

declare(strict_types=1);

namespace PhpFasty\Module\Types\Migrations;

use PhpFasty\Module\Types\Model\Registry;
use PhpFasty\Moduly\Migration\MigrationInterface;
use PhpFasty\Moduly\Wp\Support\Db;
use PhpFasty\Moduly\Wp\Support\Value;

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
        $wpdb = Db::wpdb();
        $cursor = (array) get_option(self::CURSOR, []);
        $budget = max(1, $batchSize);
        foreach ($this->types->all() as $type) {
            $fields = array_filter($type->fields, static fn ($f): bool => !empty($f->def['aliases']));
            if ([] === $fields) {
                continue;
            }
            $last = Value::int($cursor[$type->name] ?? 0);
            if ($last < 0) {
                continue; // this post type is done
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- batched ID scan; meta is read via the meta API below
            $ids = $wpdb->get_col($wpdb->prepare(
                'SELECT ID FROM %i WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d',
                $wpdb->posts,
                $type->name,
                $last,
                $budget
            ));
            foreach ($ids as $id) {
                $id = Value::int($id);
                foreach ($fields as $field) {
                    $this->backfill($id, $field);
                }
                $last = $id;
            }
            $cursor[$type->name] = count(Value::arr($ids)) < $budget ? -1 : $last;
            update_option(self::CURSOR, $cursor, false);
            if (count(Value::arr($ids)) >= $budget) {
                return false; // more of this type: next batch
            }
            $budget -= count(Value::arr($ids));
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
            $old = Value::str($old);
            if (!metadata_exists('post', $postId, $old)) {
                continue;
            }
            $raw = Value::str(get_post_meta($postId, $old, true));
            $value = $field->toStorage($raw);
            if ('' === $raw || null === $value) {
                do_action('moduly_types_invalid_value', $postId, $field->name, $old, 'alias value not convertible, skipped');

                return;
            }
            add_post_meta($postId, $field->metaKey, $value, true);

            return;
        }
    }
}
