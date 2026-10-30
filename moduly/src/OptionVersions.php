<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Migration\SchemaVersionStoreInterface;

/** Per-scope schema_version (ADR-002) in one autoloaded option: {scope: int}. */
final class OptionVersions implements SchemaVersionStoreInterface
{
    public const OPTION = 'moduly_schema_version';

    public function get(string $scope): int
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) && isset($all[$scope]) ? (int) $all[$scope] : 0;
    }

    public function set(string $scope, int $version): void
    {
        $all = get_option(self::OPTION, []);
        $all = is_array($all) ? $all : [];
        $all[$scope] = $version;
        update_option(self::OPTION, $all, true);
    }
}
