<?php

declare(strict_types=1);

namespace PhpFasty\Module\Types\Migrations;

use PhpFasty\Module\Types\Model\Registry;
use PhpFasty\Moduly\Migration\MigrationInterface;

/**
 * Records the field schema (post type → field → meta key, kind, aliases) the data was written with, so later
 * migrations know what changed. Keeps the legacy wpfasty-types plugin snapshot as `previous`. Writes once.
 */
final class Migration_0001_SchemaSnapshot implements MigrationInterface
{
    public const OPTION = 'moduly_types_schema';

    public function __construct(private readonly Registry $types)
    {
    }

    public function version(): int
    {
        return 1;
    }

    public function up(int $batchSize): bool
    {
        if (get_option(self::OPTION, null) !== null) {
            return true; // idempotent: an existing snapshot is never rewritten
        }
        add_option(self::OPTION, [
            'current' => self::snapshot($this->types),
            'previous' => (array) get_option('wpfasty_types_schema', []),
        ], '', false);
        return true;
    }

    /** @return array<string, array<string, array{key: string, type: string, aliases: list<string>}>> */
    public static function snapshot(Registry $types): array
    {
        $out = [];
        foreach ($types->all() as $type) {
            foreach ($type->fields as $name => $field) {
                $out[$type->name][(string) $name] = [
                    'key' => $field->metaKey,
                    'type' => $field->type,
                    'aliases' => array_values(array_map('strval', (array) ($field->def['aliases'] ?? []))),
                ];
            }
        }
        return $out;
    }
}
