<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Migration;

/**
 * Port: schema version per scope (theme / pack / module), e.g. a single WordPress option map.
 */
interface SchemaVersionStoreInterface
{
    public function get(string $scope): int;

    public function set(string $scope, int $version): void;
}
